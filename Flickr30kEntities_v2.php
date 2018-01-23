<?php
session_start();

// Load the database params from a config file that isn't public
$configArr = parse_ini_file("db_config.ini");
$dbServer = $configArr['dbServer'];
$dbUser = $configArr['dbUser'];
$dbPassword = $configArr['dbPassword'];
$dbName = $configArr['dbName'];
$imgWebRoot = $configArr['flickrWebRoot'];

//create a DB conn
$conn = new mysqli($dbServer, $dbUser, $dbPassword, $dbName);
if($conn->connect_error)
    die("Connection failed: ".$conn->connect_error);

$imgID = "";
if(isset($_GET["img"]))
    $imgID = $_GET["img"];
else
    $imgID = getRandomImg();

//Load the chunk table and create a mapping of token indices
//to chunk indices and chunk types
$tokenTable_chunkIdx = array();
$tokenTable_chunkType = array();
$query_chunk = "SELECT caption_idx, chunk_idx, chunk_type, ".
               "start_token_idx, end_token_idx ".
               "FROM chunk WHERE img_id='".$imgID.".jpg';";
$result_chunk = $conn->query($query_chunk);
if($result_chunk->num_rows > 0){
    while($row = $result_chunk->fetch_assoc()){
        $captionIdx = $row['caption_idx'];
        $chunkIdx = $row['chunk_idx'];
        $chunkType = $row['chunk_type'];
        $startTokenIdx = $row['start_token_idx'];
        $endTokenIdx = $row['end_token_idx'];

        if(!array_key_exists($captionIdx, $tokenTable_chunkIdx))
            $tokenTable_chunkIdx[$captionIdx] = array();
        if(!array_key_exists($captionIdx, $tokenTable_chunkType))
            $tokenTable_chunkType[$captionIdx] = array();

        for($i=$startTokenIdx; $i <= $endTokenIdx; $i++){
            $tokenTable_chunkIdx[$captionIdx][$i] = $chunkIdx;
            $tokenTable_chunkType[$captionIdx][$i] = $chunkType;
        }
    }
}

//Load the mention table for the same reason (getting token->mention)
$tokenTable_entityIdx = array();
$tokenTable_chainID = array();
$query_mention = "SELECT caption_idx, mention_idx, ".
                 "start_token_idx, end_token_idx, chain_id ".
                 "FROM mention WHERE img_id='".$imgID.".jpg';";
$result_mention = $conn->query($query_mention);
if($result_mention->num_rows > 0){
    while($row = $result_mention->fetch_assoc()){
        $captionIdx = $row['caption_idx'];
        $mentionIdx = $row['mention_idx'];
        $chainID = $row['chain_id'];
        $startTokenIdx = $row['start_token_idx'];
        $endTokenIdx = $row['end_token_idx'];

        if(!array_key_exists($captionIdx, $tokenTable_entityIdx))
            $tokenTable_entityIdx[$captionIdx] = array();
        if(!array_key_exists($captionIdx, $tokenTable_chainID))
            $tokenTable_chainID[$captionIdx] = array();

        for($i=$startTokenIdx; $i <= $endTokenIdx; $i++) {
            $tokenTable_entityIdx[$captionIdx][$i] = $mentionIdx;
            $tokenTable_chainID[$captionIdx][$i] = $chainID;
        }
    }
}

//get the token table for the image, associating the chains / chunks / mentions
//for each mention
$tokenTable = array();
$query_token = "SELECT caption_idx, token_idx, token ".
               "FROM token WHERE img_id='".$imgID.".jpg';";
$result_token = $conn->query($query_token);
if($result_token->num_rows>0){
    while($row = $result_token->fetch_assoc()){
        $captionIdx = $row['caption_idx'];
        $tokenIdx = $row['token_idx'];
        $token = $row['token'];
        if($token == ';')
            $token = "[SEMI]";
        else if($token == '#')
            $token = "[HASH]";
        else if($token == '|')
            $token = "[PIPE]";

        if(!array_key_exists($captionIdx, $tokenTable))
            $tokenTable[$captionIdx] = array();

        //get the chunk idx, chunk type, mention idx, and chain ID
        //from the previous queries
        $chunkIdx = $tokenTable_chunkIdx[$captionIdx][$tokenIdx];
        $chunkType = $tokenTable_chunkType[$captionIdx][$tokenIdx];
        $entityIdx = $tokenTable_entityIdx[$captionIdx][$tokenIdx];
        $chainID = $tokenTable_chainID[$captionIdx][$tokenIdx];

        $tokenTable[$captionIdx][$tokenIdx] =
            $token.";".$chunkIdx.";".$chunkType.";".
            $entityIdx.";".$chainID;
    }
}

//get all boxes for this image
$query_box = "SELECT box_id, x_min, x_max, y_min, y_max ".
             "FROM box WHERE img_id='".$imgID.".jpg';";
$result_box = $conn->query($query_box);
$boxCoordDict = array();
if($result_box->num_rows > 0) {
    while($row = $result_box->fetch_assoc()) {
        $boxID = $row["box_id"];
        $xMin = $row["x_min"];
        $xMax = $row["x_max"];
        $yMin = $row["y_min"];
        $yMax = $row["y_max"];
        $boxCoordDict[$boxID] = $xMin.";".$xMax.";".$yMin.";".$yMax;
    }
}

//Read the chain table to get the box/chain associations
$boxChainTable = array();
$chainOrigDict = array(); //We artificially need this, given the old structure of things
$query_chain = "SELECT chain_id, assoc_box_ids ".
               "FROM chain WHERE img_id='".$imgID.".jpg';";
$result_chain = $conn->query($query_chain);
if($result_chain->num_rows > 0) {
    while($row = $result_chain->fetch_assoc()){
        $chainID = $row['chain_id'];
        $chainOrigDict[$chainID] = 1;

        $assocBoxes = $row['assoc_box_ids'];
        $boxes = array();
        if($assocBoxes != null && $assocBoxes != "NULL")
            $boxes = explode("|", $assocBoxes);
        foreach($boxes as $box) {
            if(!array_key_exists($box, $boxChainTable))
                $boxChainTable[$box] = array();
            array_push($boxChainTable[$box], $chainID);
        }
    }
}

//get the image dimensions and comments
$query_img = "SELECT width, height, anno_comments ".
             "FROM image WHERE img_id='".$imgID.".jpg';";
$result_img = $conn->query($query_img);
$width=-1;
$height=-1;
$reviewComments='';
if($result_img->num_rows > 0) {
    while($row = $result_img->fetch_assoc()) {
        $width = $row["width"];
        $height = $row["height"];
        $reviewComments = $row["anno_comments"];
    }
}
$reviewComments='';

//dont forget to close the conn
$conn->close();

function getReviewCommentStr()
{
    global $reviewComments;
    if($reviewComments != '')
        return $reviewComments;
    else
        return "";
}

//These functions return empty strings as they
//are only here to interface with much older code / structures
function getNeedsAdditionalStr()
{
    return "";
}
function getCaptionTypoArrStr()
{
    return "";
}
function getCaptionIrrelevantArrStr()
{
    return "";
}
function getCaptionMischunkArrStr()
{
    return "";
}
function getChainOrigDictStr()
{
    return "";
}

function getRandomImg()
{
    global $conn;
    $query_img = "SELECT img_id ".
                 "FROM image ORDER BY RAND() LIMIT 1";
    $result = $conn->query($query_img);
    $imgID = "";
    if($result->num_rows > 0)
        while($row = $result->fetch_assoc())
            $imgID = $row['img_id'];
    return str_replace(".jpg", "", $imgID);
}

/**Returns the string representation of the token table
 * (used for javascript)
 *
 * @return mixed|string - The token table
 */
function getTokenTableStr()
{
    global $tokenTable;

    $tokenTableStr = "";
    for($i=0; $i < sizeof($tokenTable); $i++) {
        for($j=0; $j < sizeof($tokenTable[$i]); $j++) {
            $tokenTableStr .= $tokenTable[$i][$j];
            if($j < sizeof($tokenTable[$i])-1)
                $tokenTableStr .= "#";
        }
        if($i < sizeof($tokenTable)-1)
            $tokenTableStr .= "|";
    }
    $tokenTableStr = str_replace("\"", "'", $tokenTableStr);
    return $tokenTableStr;
}

/**Returns the string representation of the
 * box-coordinate dictionary (used for javascript)
 *
 * @return string - The boxCoordDict
 */
function getBoxCoordDictStr()
{
    global $boxCoordDict;
    $boxArrStr = "";
    foreach($boxCoordDict as $boxID => $coordStr)
        $boxArrStr .= $boxID."#".$coordStr."|";
    return $boxArrStr;
}

/**Returns the string representation of the
 * box-chain table (used for javascript)
 *
 * @return string - The boxChain_table string
 */
function getBoxChainTableStr()
{
    global $boxChainTable;
    $boxChainTableStr = "";
    foreach($boxChainTable as $boxID => $chainIDArr) {
        $boxChainTableStr .= $boxID."#";
        for($i=0; $i < sizeof($chainIDArr); $i++)
            $boxChainTableStr .= $chainIDArr[$i] . ";";
        $boxChainTableStr.="|";
    }
    return $boxChainTableStr;
}

/**Returns the url for this image
 *
 * @return string - The URL for this image
 */
function getImgSrc()
{
    global $imgWebRoot, $imgID;
    return $imgWebRoot.$imgID.'.jpg';
}

/**Returns the width of the canvas, which is just
 * the image width + 25px
 */
function getCanvasWidth()
{
    global $width;
    return $width + 25;
}

function getModeHtm()
{
    $modeHtm = '<tr><td id="td:modeIcon" style="width:35px"></td>'.
        '<td class="interfaceText" id="td:modeLabel"> </td>'.
        '</tr><tr><td id="td:modeButton" colspan=2> </td>'.
        '</tr>';
    return $modeHtm;
}
?>

<html>
<head>
    <link rel="stylesheet" type="text/css" href="imgCleanup_style.css">
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/font-awesome/4.4.0/css/font-awesome.min.css">
</head>
<body onload="init()">

<script language="javascript" type="text/javascript">
    /**** Init functions ****/
        //our dicts, in three groups:
        //1) token tables, associationg [capIdx][tokenIdx] with
        //   token text, chunkIdx, and entityIdx
    var tokenTable_text;
    var tokenTable_chunk;

    //2) partition tables, associating [captionIdx][chunkIdx] with chunk
    //   type and entityIdx, and associationg [captionIdx][entityIdx] with
    //   chainID
    var chunkTable_type;
    var chunkTable_entity;
    var entityTable_chain;

    //3) display dictionaries, associating [chainID] with the color to which
    //   its assigned, [boxID] -> min/max x/y coords, [boxID] -> chainID
    //   and [boxID] -> whether it's displayed
    var chainColorDict;
    var boxCoordDict;
    var boxChainArrDict;
    var boxDispDict;
    var chainColorDispDict;

    //the last update vars, allowing us to keep track of
    //which thing was last updated, so we can keep state
    //Valid update types are
    // {token, chunk, entity}
    //and valid update IDs are
    // {capIdx|tokenIdx, capIdx|chunkIdx, capIdx|entityIdx},
    //respectively
    var lastUpdate_ID;
    var lastUpdate_type;
    var lastUpdate_state;

    //for token updates, we need to keep track of our chains
    //in order
    var chainArr;
    var chainOrigDict;

    //in order to cycle through our chunk types, we need to
    //have a collection of the possibilities
    var chunkTypeArr;

    //keep a flag for whether we're showing all chunks or just NPs
    var chunkMode;

    //start timestamp (when init was called)
    var startTS;

    //the caption checkbox strings, split per caption
    var captionTypoStrArr;
    var captionIrrelevantStrArr;
    var captionMischunkStrArr;

    var mischunkCapsStr;

    /**Initialization function; populates global vars and sets up
     * the webpage
     */
    function init()
    {
        //init all of our global vars
        boxCoordDict = new Object();
        boxChainArrDict = new Object();
        boxDispDict = new Object();
        chainColorDispDict = new Object();

        lastUpdate_ID = null;
        lastUpdate_type = null;
        lastUpdate_state = null;

        chunkMode = false;
        chunkTypeArr = ["NP", "VP", "PP", "SBAR", "PRT",
            "ADJP", "ADVP", "CONJP", "INTJP"];

        //first, populate the token and box tables from the php str
        initTables();

        //init chain colors
        initChainColors();

        //make sure to display the current mode
        updateModeCell();

        //now that we have token tables and colors assigned to chains,
        //print our captions
        printCaptions();

        //populate our chain table
        updateChainTableElement();

        //draw boxes
        drawBoxes();

        //get the start TS
        startTS = Math.floor(Date.now() / 1000);
    }

    /**Builds the internal tables (using php) for
     * text, chain, and box assignments
     */
    function initTables()
    {
        //init our tables
        tokenTable_text = new Object();
        tokenTable_chunk = new Object();
        chunkTable_type = new Object();
        chunkTable_entity = new Object();
        entityTable_chain = new Object();

        //init the chain arr with 0 in the first position
        chainArr = new Array();
        chainOrigDict = new Object();
        chainArr.push('0');
        chainOrigDict['0'] = true;

        //strings retrieved from php
        var tokenTableStr = "<?php echo getTokenTableStr();?>";
        var boxCoordDictStr = "<?php echo getBoxCoordDictStr();?>";
        var boxChainTableStr = "<?php echo getBoxChainTableStr();?>";
        var chainOrigDictStr = "<?php echo getChainOrigDictStr();?>";
        var captionTypoStr = "<?php echo getCaptionTypoArrStr();?>";
        var captionIrrelevantStr = "<?php echo getCaptionIrrelevantArrStr();?>";
        var captionMischunkStr = "<?php echo getCaptionMischunkArrStr();?>";
        mischunkCapsStr = "";

        //caption arrays based on those php strings (each is an array of strings
        //with vars for each caption
        var tokenArr_caption = tokenTableStr.split("|");
        var boxCoordDictStrArr = boxCoordDictStr.split("|");
        var boxChainTableStrArr = boxChainTableStr.split("|");
        var chainOrigDictStrArr = chainOrigDictStr.split("|");
        captionTypoStrArr = captionTypoStr.split("|");
        captionIrrelevantStrArr = captionIrrelevantStr.split("|");
        captionMischunkStrArr = captionMischunkStr.split("|");

        //our chain strings are chains divided by pipes (|), chainIDs divided
        //from isOrig flags by hashes (#)
        for(var chainIdx=0; chainIdx < chainOrigDictStrArr.length; chainIdx++){
            var chainStrArr = chainOrigDictStrArr[chainIdx].split("#");
            var chainID = chainStrArr[0];
            var isOrig = false;
            if(chainStrArr[1] == "1" || chainStrArr[1] == 1){
                isOrig = true;
            }
            if(chainID != "" && chainID != null){
                chainArr.push(chainID);
                chainOrigDict[chainID] = isOrig;
            }
        }

        //our PHP token string should be
        //captions divided by pipes (|), tokens divided by hashes (#), and
        //internal attributes divided by semicolons (;)
        for(var captionIdx=0; captionIdx < tokenArr_caption.length; captionIdx++)
        {
            //initialize the rows in the table
            tokenTable_text[captionIdx] = new Object();
            tokenTable_chunk[captionIdx] = new Object();
            chunkTable_type[captionIdx] = new Object();
            chunkTable_entity[captionIdx] = new Object();
            entityTable_chain[captionIdx] = new Object();

            //populate tables using token info
            var tokenArr_token = tokenArr_caption[captionIdx].split("#");
            for(var tokenIndex=0; tokenIndex < tokenArr_token.length; tokenIndex++)
            {
                //the token string is actually much more complex than we need it to be.
                //This is in case we need to switch the logic (np nonvis, entities, etc)
                //to javascript. For now, though, we're just not using most of the string
                var tokenArr = tokenArr_token[tokenIndex].split(";");
                var text = tokenArr[0];
                var chunkIdx = parseInt(tokenArr[1]);
                var chunkType = tokenArr[2];
                if(chunkType == "" || chunkType == "null" ||
                    chunkType == "NULL" || chunkType == "undefined") {
                    chunkType = null;
                }
                var entityIdx = parseInt(tokenArr[3]);
                var chainID = tokenArr[4];
                var validChunk = chunkIdx != -1;
                var validChain = chainID != "-1" && chainID != "" &&
                    chainID != "null" && chainID != null &&
                    chainID != -1;
                var validEntity = entityIdx != -1 && validChain && !isNaN(entityIdx);
                tokenTable_text[captionIdx][tokenIndex] = text;

                //it's possible for there to be chains present in the tokens
                //that aren't present in the chain table. Add these chains
                //too
                if(chainID != "" && chainID != null && chainArr.indexOf(chainID) < 0)
                    chainArr.push(chainID);

                //we're making two assumptions by populating our tables
                //in this way
                //1) any token's chunkType / entityIdx is the
                //   same as any other token's, so long as the
                //   captionIdx and chunkIdx are the same
                //   (thus, overwrites don't matter)
                if(validChunk){
                    tokenTable_chunk[captionIdx][tokenIndex] = chunkIdx;
                    chunkTable_type[captionIdx][chunkIdx] = chunkType;
                    if(validEntity){
                        chunkTable_entity[captionIdx][chunkIdx] = entityIdx;
                    }
                }
                //2) any token's chainID is the same as any other's, so
                //   long as the entityIdxes are the same
                if(validEntity){
                    entityTable_chain[captionIdx][entityIdx] = chainID;
                }
            }
        }

        //now that the chain arr is populated, sort it
        chainArr.sort();

        //our box strings should be
        //boxes divided by pipes (|), box IDs divided from coords by hashes (#)
        //and coords themselves divided by underscores (_)
        var boxIndex;
        var boxStr;
        var boxID;
        for(boxIndex=0; boxIndex < boxCoordDictStrArr.length; boxIndex++) {
            boxStr = boxCoordDictStrArr[boxIndex].split("#");
            boxID = boxStr[0];
            var coordStr = boxStr[1];
            if(boxID != "") {
                boxCoordDict[boxID] = coordStr.split(";");
            }
        }

        //our box-chain strings will be divided by pipes (|),
        //where box IDs are divided from chain IDs by hashes (#), and
        //chain IDs are divided by underscores (_)
        for(boxIndex=0; boxIndex < boxChainTableStrArr.length; boxIndex++) {
            boxStr = boxChainTableStrArr[boxIndex].split("#");
            boxID = boxStr[0];
            if(boxID != "") {
                boxChainArrDict[boxID] = boxStr[1].split(";");
            }
        }

        //populate the box-display dictionary (all boxes should be hidden
        //by default
        for(boxID in boxChainArrDict) {
            boxDispDict[boxID] = false;
        }

        //populate the chainColor-display dictionary (all chains have hidden
        //colors by default)
        for(chainIdx=0; chainIdx < chainArr.length; chainIdx++){
            chainColorDispDict[chainArr[chainIdx]] = true;
        }
    }

    /**** End Init functions ****/

    /**** Update functions ****/

    /**Updates the color assignments for the most recently updated
     * token's chain or initializes colors for all the chains
     */
    function initChainColors()
    {
        chainColorDict = new Object();
        var colorArr = ["red", "mediumblue", "forestgreen",
            "darkorchid", "steelblue", "fuchsia", "dodgerblue",
            "saddlebrown", "mediumvioletred", "darkkhaki",
            "darkorange", "olivedrab", "lightslategray"];
        var currentColorIndex = 0;
        for(var chainIdx=0; chainIdx < chainArr.length; chainIdx++){
            var chainID = chainArr[chainIdx];
            if(chainID != '0'){
                //random color code stolen from the internet
                //http://www.paulirish.com/2009/random-hex-color-code-snippets/
                var color = '#'+Math.floor(Math.random()*16777215).toString(16);
                if(currentColorIndex < colorArr.length)
                {
                    color = colorArr[currentColorIndex];
                    currentColorIndex++;
                }
                chainColorDict[chainID] = color;
            }
        }
    }

    function updateChainColors(chainID)
    {
        var colorArr = ["red", "mediumblue", "forestgreen",
            "darkorchid", "steelblue", "fuchsia", "dodgerblue",
            "saddlebrown", "mediumvioletred", "darkkhaki",
            "darkorange", "olivedrab", "lightslategray"];
        //push a new random color onto the array, in case we need a new color
        colorArr.push('#'+Math.floor(Math.random()*16777215).toString(16));

        //we only update the color for this chain IFF it's a new chain
        if(chainID != '0' && !(chainID in chainColorDict)){
            //leave only unused colors in the color arr
            for(var oldChainID in chainColorDict){
                var idxToRemove = colorArr.indexOf(chainColorDict[oldChainID]);
                if(idxToRemove > -1){
                    colorArr.splice(idxToRemove, 1);
                }
            }

            //now the lowest index in the colorArr will reflect either one of
            //the unused hand-selected colors or the new, random color
            chainColorDict[chainID] = colorArr[0];
        }
    }

    /**Prints the captions, given populated token tables and chains that
     * have been assigned colors
     */
    function printCaptions()
    {
        var captionHtm = "";
        captionHtm += "<table>";
        for(var captionIdx in tokenTable_text) {
            if (tokenTable_text.hasOwnProperty(captionIdx)) {
                captionHtm += "<tr>";
                captionHtm += "<td class=\"captionText\" ";
                if(mischunkCapsStr.indexOf(captionIdx) > -1){
                    captionHtm += "bgcolor=#FFFF00 ";
                }
                captionHtm += ">";

                var debugStr = "";

                var prevPartitionIdx = null;
                var buildingPartition = false;
                for(var tokenIdx in tokenTable_text[captionIdx]) {
                    var text = null;
                    var chunkIdx = null;
                    var entityIdx = null;
                    var chainID = null;
                    var chunkType = null;

                    if (tokenTable_text[captionIdx].hasOwnProperty(tokenIdx)){
                        text = tokenTable_text[captionIdx][tokenIdx];
                        if(text == '[SEMI]'){
                            text = "&#59;";
                        } else if(text == '[HASH]'){
                            text = "&#35;";
                        } else if(text == "[PIPE]"){
                            text = "&#124;";
                        }
                    }
                    if (tokenIdx in tokenTable_chunk[captionIdx]){
                        chunkIdx = tokenTable_chunk[captionIdx][tokenIdx];
                    }
                    if(chunkIdx in chunkTable_entity[captionIdx]){
                        entityIdx = chunkTable_entity[captionIdx][chunkIdx];
                    }

                    if(chunkIdx != null && chunkIdx in chunkTable_type[captionIdx]){
                        chunkType = chunkTable_type[captionIdx][chunkIdx];
                    }
                    if(entityIdx != null && entityIdx in entityTable_chain[captionIdx]){
                        chainID = entityTable_chain[captionIdx][entityIdx];
                    }

                    //get the current partition idx, depending on what mode this is
                    var currentPartitionIdx = null;
                    if(chunkMode) {
                        currentPartitionIdx = chunkIdx;
                    } else {
                        currentPartitionIdx = entityIdx;
                    }

                    //if partition indices differ, this is a boundary
                    var openedOrClosedPartition = false;
                    if(currentPartitionIdx != prevPartitionIdx) {
                        //if this is the end of a partition, close it out
                        if(prevPartitionIdx != null) {
                            if(!chunkMode){
                                var prevChainID = entityTable_chain[captionIdx][prevPartitionIdx];
                                var len = prevChainID.length;
                                captionHtm += " <sub "+
                                    "name=\"" + captionIdx + "|" + prevPartitionIdx + "\" " +
                                    "id=\"" + captionIdx + "|" + prevPartitionIdx + "\">";
                                if(prevChainID == '0') {
                                    captionHtm += "&ndash;&ndash;&ndash;";
                                } else {
                                    captionHtm += prevChainID.substring(len - 3, len);
                                }
                                captionHtm +=  "</sub>";
                            }

                            captionHtm += "]</span> ";
                            openedOrClosedPartition = true;
                            buildingPartition = false;
                        }

                        //if this is the start of a partition, open it up
                        if(currentPartitionIdx != null) {
                            captionHtm += "<span style=\"font-weight:bold;";
                            captionHtm += "hover:"+chainColorDict[chainID]+";";

                            //in coref mode, add a color (if allowed)
                            if(!chunkMode && chainColorDispDict[chainID]){
                                captionHtm += "color:"+chainColorDict[chainID]+";";
                            }
                            captionHtm += "\" ";

                            //also in coref mode, add a mouse enter / leave event handler
                            if(!chunkMode) {
                                //If we're _not_ showing colors, we want to add
                                //the this.style.color alteration to the mouseover events
                                //(we need to do this outside of the functions
                                //because if we call the functions we have to
                                //redraw the captions, and if we redraw the captions
                                //the mouseleave event doesn't fire)
                                captionHtm += "onmouseenter=\"";
                                if(!chainColorDispDict[chainID]){
                                    captionHtm += "this.style.color='" +
                                        chainColorDict[chainID] + "';";
                                }
                                captionHtm += "mentionMouseEnter(" + chainID + ")\" ";
                                captionHtm += "onmouseleave=\"";
                                if(!chainColorDispDict[chainID]){
                                    captionHtm += "this.style.color='black';";
                                }
                                captionHtm += "mentionMouseLeave("+chainID+")\" ";
                            }
                            captionHtm += "> [";

                            //if we're displaying chunks, add the chunk type superscript
                            if(chunkMode && chunkType != null) {
                                captionHtm +=
                                    "<sup "+
                                    "name=\"" + captionIdx + "|" +
                                    currentPartitionIdx + "\" " + "id=\"" +
                                    captionIdx + "|" + currentPartitionIdx +
                                    "\">" + chunkType + "</sup> ";
                            }
                            openedOrClosedPartition = true;
                            buildingPartition = true;
                        }
                    }

                    //append this mention to the debug string
                    debugStr += "[chunk:" + chunkIdx +
                        ";chunk_type:" + chunkType +
                        ";entity:" + entityIdx +
                        ";chain:" + chainID +
                        ";text: " + text + " ]  ";

                    //if we didn't open or close a partition, add a space
                    if(!openedOrClosedPartition) {
                        captionHtm += " ";
                    }

                    //each token is it's own clickable span in chunk mode
                    if(chunkMode) {
                        var tokenID = captionIdx + "|" + tokenIdx;
                        captionHtm += "<span id=\"" + tokenID + "\" name=\""+
                            tokenID + "\" ";
                    }
                    if(chunkMode) {
                        captionHtm += "</span>";
                    }
                    captionHtm += text;
                    captionHtm += "<input type=\"hidden\" name=\"hdn:token[" +
                        captionIdx + "][" + tokenIdx +
                        "]\" value=\"entityIdx:" + entityIdx +
                        ";chainID:" + chainID +
                        ";chunkIdx:" + chunkIdx  +
                        ";chunkType:"+ chunkType + "\"/>";
                    prevPartitionIdx = currentPartitionIdx;
                }

                //if we were still building a partition at the end of the caption,
                //close it
                if(buildingPartition) {
                    if(prevPartitionIdx != null) {
                        if(!chunkMode){
                            var prevChainID = entityTable_chain[captionIdx][prevPartitionIdx];
                            var len = prevChainID.length;
                            captionHtm += " <sub "+
                                "name=\"" + captionIdx + "|" + prevPartitionIdx + "\" " +
                                "id=\"" + captionIdx + "|" + prevPartitionIdx + "\">";
                            if(prevChainID == '0') {
                                captionHtm += "&mdash;";
                            } else {
                                captionHtm += prevChainID.substring(len - 3, len);
                            }
                            captionHtm +=  "</sub>";
                        }

                        captionHtm += "]</span>";
                        openedOrClosedPartition = true;
                        buildingPartition = false;
                    }
                }

                //close out this caption
                captionHtm += "</td>";

                //only print this caption's stuff if it's the one we've
                //most recently updated (or if there isn't an update)
                var lastUpdateCaption = -1;
                if(lastUpdate_ID != null){
                    lastUpdateCaption = parseInt(lastUpdate_ID.split("\\|")[0]);
                }

                captionHtm += "</tr>";
            }
        }
        captionHtm += "</table>";

        //update the cell we're putting these captions in
        var tokenTableElement = document.getElementById("td:captionCell");
        tokenTableElement.innerHTML = "<tr><td>"+captionHtm+"</td></tr>";
    }

    /**Updates the chain table, where columns are chains
     * with representative chunks, and rows are
     * boxes
     */
    function updateChainTableElement()
    {
        var r;
        var c;
        var rowID;
        var colID;
        var boxID;
        var chainID;
        var numCols = chainArr.length + 1;

        //store the state of the old table
        var tableElement = document.getElementById("tbl:chainTable");
        var checkboxStates = new Object();
        for (r = 2; r < tableElement.rows.length; r++) {
            var row = tableElement.rows[r];
            rowID = row.cells[0].innerHTML;
            checkboxStates[rowID] = new Object();
            for (c = 1; c < row.cells.length; c++) {
                var colHead = tableElement.rows[1].cells[c].id;
                colID = colHead.split(":")[1];
                var checkbox = row.cells[c].childNodes[0];
                var isChecked = false;
                if(checkbox != null && checkbox.checked)
                    isChecked = true;
                checkboxStates[rowID][colID] = isChecked;
            }
        }

        //get a list of all those non-new chain IDs
        //that don't have boxes at all
        var noBoxChainArr = new Array();
        for(c=1; c<numCols; c++) {
            chainID = chainArr[c-1];
            if(chainOrigDict[chainID]){
                var foundBox = false;
                for(boxID in boxChainArrDict){
                    if(boxChainArrDict[boxID].indexOf(chainID) > -1){
                        foundBox = true;
                        break;
                    }
                }
                if(!foundBox)
                    noBoxChainArr.push(chainID);
            }
        }

        //build our new table
        //first with column headers corresponding with chain IDs
        var chainTableHtm = "";
        chainTableHtm += "<tr><td colspan=" + numCols +
            "class=\"centeredTableCell\" >"+
            "Chain / Bounding Box Assignments<hr/>"+
            "</td></tr>";
        chainTableHtm += "<tr>";
        chainTableHtm += "<th></th>";
        for(c=1; c<numCols; c++) {
            chainID = chainArr[c-1];
            //only add chains that don't appear in our set of original
            //chains without boxes
            if(noBoxChainArr.indexOf(chainID) < 0) {
                var color = chainColorDict[chainID];
                chainTableHtm += "<th style=\"color:"+color+";\" ";
                chainTableHtm += "id=\"th_chain:" + chainID + "\" ";
                chainTableHtm += "onclick=\"boxToggleHandler()\" ";
                chainTableHtm += "class=\"centeredTableCell\" "
                chainTableHtm += ">";
                var len = chainID.length;
                chainTableHtm += chainID.substring(len-3, len);
                chainTableHtm += "</th>";
            }
        }
        chainTableHtm += "</tr>";

        //now add rows for each box
        for(boxID in boxCoordDict) {
            chainTableHtm += "<tr>";
            chainTableHtm += "<td class=\"centeredTableCell\" ";
            chainTableHtm += "id=\"td_box:" + boxID + "\" ";
            chainTableHtm += "onclick=\"boxToggleHandler()\" ";
            chainTableHtm += ">";
            chainTableHtm += boxID;
            chainTableHtm += "</td>";
            for(c=1; c<numCols; c++) {
                colID = chainArr[c-1];

                //only add chains that don't appear in our set of original
                //chains without boxes
                if(noBoxChainArr.indexOf(colID) < 0) {
                    chainTableHtm += "<td class=\"centeredTableCell\" >";
                    chainTableHtm += "<input ";
                    chainTableHtm += "onclick=\"boxChainCheckHandler("+
                        colID + ", " + boxID + ")\" ";
                    chainTableHtm += "type=\"checkbox\" ";
                    if (boxID in boxChainArrDict &&
                        boxChainArrDict[boxID].indexOf(colID) > -1) {
                        chainTableHtm += "checked ";
                    }
                    else if (boxID in checkboxStates &&
                        colID in checkboxStates[boxID] &&
                        checkboxStates[boxID][colID]) {
                        chainTableHtm += "checked ";
                    }

                    //if this is an original box/chain association, disable it
                    //

                    //this is a bit sketch, but we know that
                    //new chains have underscores, and only
                    //new chains may be modified, so disable
                    //everything else
                    chainTableHtm += "disabled ";

                    chainTableHtm += ">";
                    chainTableHtm += "</td>";
                }
            }
            chainTableHtm += "</tr>";
        }

        //update the table
        tableElement.innerHTML = chainTableHtm;
    }

    /**Draws the image and bounding boxes, using html5 canvas
     */
    function drawBoxes()
    {
        var boxID;

        //get the canvas element and a 2d context
        var canvas = document.getElementById("canvas");
        var context = canvas.getContext("2d");

        //draw the image
        var img = new Image();
        img.src = "<?php echo getImgSrc();?>";
        context.drawImage(img, 0, 0);

        //get a mapping of boxes to colors (based on chains)
        var boxColorDict = new Object();
        for(boxID in boxDispDict) {
            if(boxDispDict.hasOwnProperty(boxID) && boxDispDict[boxID]) {
                for(var i=0; i<boxChainArrDict[boxID].length; i++) {
                    var chainID = boxChainArrDict[boxID][i];
                    if(chainID in chainColorDict) {
                        var color = chainColorDict[chainID];
                        if(boxID in boxColorDict && boxColorDict[boxID] != color) {
                            //in the event of a color conflict, this box will
                            //be displayed as light gray
                            color = "#dddddd";
                        }
                        boxColorDict[boxID] = color;
                    }
                }
            }
        }

        //draw each box in the dict
        for(boxID in boxColorDict)
        {
            var coords = boxCoordDict[boxID];
            var xMin = coords[0];
            var xMax = coords[1];
            var yMin = coords[2];
            var yMax = coords[3];
            context.fillStyle = boxColorDict[boxID];
            context.globalAlpha = 0.3;
            context.fillRect(xMin, yMin, xMax-xMin, yMax-yMin);
            context.strokeStyle = "black";
            context.lineWidth = 2;
            context.globalAlpha = 1;
            context.strokeRect(xMin, yMin, xMax-xMin, yMax-yMin);
        }
    }

    /**Handler function for the show/hide boxes button;
     * toggles box visibility based on the following
     * criteria
     * 1) all off if any on
     * 2) all on if all off
     */
    function toggleBoxes()
    {
        var foundOn = false;
        var boxID;
        for(boxID in boxDispDict)
        {
            foundOn |= boxDispDict[boxID];
        }
        for(boxID in boxDispDict)
        {
            boxDispDict[boxID] = !foundOn;
        }
        drawBoxes();
    }

    function toggleChainColors()
    {
        var foundOn = false;
        var chainID;
        for(chainID in chainColorDispDict) {
            foundOn |= chainColorDispDict[chainID];
        }
        for(chainID in chainColorDispDict) {
            chainColorDispDict[chainID] = !foundOn;
        }
        printCaptions();
    }

    /**Turns visibility for all chunks on or off
     */
    function toggleMode()
    {
        chunkMode = !chunkMode;
        updateModeCell();
        printCaptions();
    }

    function updateModeCell()
    {
        if(document.getElementById("td:modeIcon") != null &&
            document.getElementById("td:modeLabel") != null &&
            document.getElementById("td:modeButton") != null){
            var modeStr = "";
            var iconStr = "";
            if(chunkMode) {
                modeStr = "Chunking";
                iconStr = "fa fa-i-cursor";
            } else {
                modeStr = "Coreference";
                iconStr = "fa fa-link";
            }
            document.getElementById("td:modeIcon").innerHTML =
                "<span style=\"font-size:1em\" class=\"fa-stack fa-lg\">"+
                "<i style=\"color:black;\" class=\"fa fa-circle fa-stack-2x\"></i>"+
                "<i class=\""+ iconStr + " fa-stack-1x fa-inverse\"></i></span>";
            document.getElementById("td:modeLabel").innerHTML = modeStr + " Mode";
            document.getElementById("td:modeButton").innerHTML =
                "<input type=\"button\" value=\"Toggle Chunk / Coref Mode\" " +
                "onclick=\"toggleMode()\"/>";
        }
    }
    /**** End Update functions ****/

    /**** Event Handler functions ****/

    /**Handles the token click event, which is the most beautiful
     * and horrifying thing a user can do
     */
    function tokenClickHandler(e)
    {
        //get calling element ID, stolen from the internet
        //http://www.javascripter.net/faq/eventtargetsrcelement.htm
        var elem, evt = e ? e:event;
        if (evt.srcElement)
        {
            elem = evt.srcElement;
        }
        else if (evt.target)
        {
            elem = evt.target;
        }
        var elementID = elem.id;

        //this element ID is the capIdx|tokenIdx
        var capTokenArr = elementID.split("|");
        var captionIdx = parseInt(capTokenArr[0]);
        var tokenIdx = parseInt(capTokenArr[1]);

        //update the token assignment
        changeTokenAssignment(captionIdx, tokenIdx);

        //re-print our captions
        printCaptions();

        return true;
    }

    /**Handles the entity chain click event, changing the
     * entity's chain assignment
     */
    function entityChainClickHandler(e)
    {
        //get calling element ID, stolen from the internet
        //http://www.javascripter.net/faq/eventtargetsrcelement.htm
        var elem, evt = e ? e:event;
        if (evt.srcElement)
            elem = evt.srcElement;
        else if (evt.target)
            elem = evt.target;
        var elementID = elem.id;

        //this element ID is the capIdx|entityIdx
        var capEntityArr = elementID.split("|");
        var captionIdx = parseInt(capEntityArr[0]);
        var entityIdx = parseInt(capEntityArr[1]);
        var oldChainID = entityTable_chain[captionIdx][entityIdx];

        //we always show boxes as part of the mouseover event, which means
        //clicking this chainID to change it has left a box on the canvas
        //that won't be cleared by a mouseleave (since that isn't called
        //when captions are reprinted). Clear them here
        mentionMouseLeave(oldChainID);

        //break this entity's chain association
        delete entityTable_chain[captionIdx][entityIdx];
        deleteChain(oldChainID);

        //get the next chain idx (if we removed the last entity in a chain
        //just now, we'll retrieve the first element in the array)
        var chainIdx = chainArr.indexOf(oldChainID);
        var nextChainIdx = chainIdx + 1;

        //if the next index is beyond the size of the chain arr,
        //add a new chain for this entity idx (which will
        //add that chain to the beginning of the chain arr
        if(nextChainIdx >= chainArr.length) {
            createNewChain(captionIdx, entityIdx);
        } else {
            entityTable_chain[captionIdx][entityIdx] = chainArr[nextChainIdx];
        }

        //store this as the last update
        lastUpdate_type = "entity";
        lastUpdate_ID = captionIdx + "|" + entityIdx;

        //reprint our captions
        printCaptions();
        return true;
    }

    /**Handles chunk type click event, changing
     * the chunk's type assignment
     */
    function chunkTypeClickHandler(e)
    {
        //get calling element ID, stolen from the internet
        //http://www.javascripter.net/faq/eventtargetsrcelement.htm
        var elem, evt = e ? e:event;
        if (evt.srcElement)
        {
            elem = evt.srcElement;
        }
        else if (evt.target)
        {
            elem = evt.target;
        }
        var elementID = elem.id;

        //this element ID is the capIdx|chunkIdx
        var capChunkArr = elementID.split("|");
        var captionIdx = parseInt(capChunkArr[0]);
        var chunkIdx = parseInt(capChunkArr[1]);

        //get the next type from the array
        var prevType = chunkTable_type[captionIdx][chunkIdx];
        var typeIdx = chunkTypeArr.indexOf(prevType) + 1;
        if(typeIdx >= chunkTypeArr.length){
            typeIdx = 0;
        }

        //update the chunk assignment
        changeChunkTypeAssig(captionIdx, chunkIdx, chunkTypeArr[typeIdx]);

        //store this as the last update
        lastUpdate_type = "chunk";
        lastUpdate_ID = captionIdx + "|" + chunkIdx;

        //re-print our captions
        printCaptions();

        return true;
    }

    /**The event handler for both of the box-toggling
     * elements (chains and boxes from the table).
     * Toggles boxes on and off
     *
     * @param e - The element that called this handler
     */
    function boxToggleHandler(e)
    {
        var boxID;
        var i;

        //get calling element ID, stolen from the internet
        //http://www.javascripter.net/faq/eventtargetsrcelement.htm
        var elem, evt = e ? e:event;
        if (evt.srcElement)
        {
            elem = evt.srcElement;
        }
        else if (evt.target)
        {
            elem = evt.target;
        }
        var elementID = elem.id;

        //split the element ID on the colon
        //and switch on whether it's a chain or box
        var elemIdArr = elementID.split(":");
        if(elemIdArr[0] == "th_chain")
        {
            //get all the checked boxes for this chain
            var boxArr = new Array();
            var chainID = elemIdArr[1];
            var tableElement = document.getElementById("tbl:chainTable");
            for (var r = 2; r < tableElement.rows.length; r++) {
                var row = tableElement.rows[r];
                var rowID = row.cells[0].innerHTML;
                for (var c = 1; c < row.cells.length; c++) {
                    var colHead = tableElement.rows[1].cells[c].id;
                    if(chainID == colHead.split(":")[1]){
                        var checkbox = row.cells[c].childNodes[0];
                        if(checkbox != null && checkbox.checked)
                        {
                            boxArr.push(rowID);
                        }
                    }
                }
            }

            //toggle all these boxes, depending on whether
            //any are off (basically:
            //1) if all off, turn on
            //2) if some-not-all on, turn on
            //3) if all on, turn off
            var foundOn = false;
            var foundOff = false;
            for(i=0; i<boxArr.length; i++)
            {
                boxID = boxArr[i];

                if(boxDispDict[boxID])
                {
                    foundOn = true;
                }
                else
                {
                    foundOff = true;
                }
            }
            for(i=0; i<boxArr.length; i++)
            {
                boxID = boxArr[i];

                if(foundOff && !foundOn)
                {
                    boxDispDict[boxID] = true;
                }
                else if(foundOff && foundOn)
                {
                    boxDispDict[boxID] = true;
                }
                else if(foundOn && !foundOff)
                {
                    boxDispDict[boxID] = false;
                }
            }
        }
        else if(elemIdArr[0] == "td_box")
        {
            //toggle this box
            boxID = elemIdArr[1];
            boxDispDict[boxID] = !boxDispDict[boxID];
        }

        //now redraw all the boxes
        drawBoxes();
    }

    function boxChainCheckHandler(chainID, boxID)
    {
        //get the state of the checkbox
        var tableElement = document.getElementById("tbl:chainTable");
        var isChecked = false;
        for (var r = 2; r < tableElement.rows.length; r++) {
            var row = tableElement.rows[r];
            var rowID = row.cells[0].innerHTML;
            if(boxID == rowID){
                for (var c = 1; c < row.cells.length; c++) {
                    var colHead = tableElement.rows[1].cells[c].id;
                    var colID = colHead.split(":")[1];
                    if(chainID == colID){
                        var checkbox = row.cells[c].childNodes[0];
                        if(checkbox != null && checkbox.checked) {
                            isChecked = true;
                        }
                    }
                }
            }
        }

        if(isChecked) {
            boxChainArrDict[boxID].push("" + chainID);
        } else {
            boxChainArrDict[boxID].splice(boxChainArrDict[boxID].indexOf("" + chainID), 1);
        }
    }

    function mentionMouseEnter(chainID)
    {
        for(var boxID in boxChainArrDict) {
            if (boxChainArrDict[boxID].indexOf(""+chainID) > -1) {
                boxDispDict[boxID] = true;
            }
        }
        drawBoxes();
    }

    function mentionMouseLeave(chainID)
    {
        for(var boxID in boxChainArrDict) {
            if (boxChainArrDict[boxID].indexOf(""+chainID) > -1) {
                boxDispDict[boxID] = false;
            }
        }
        drawBoxes();
    }

    /**** Event Handler functions ****/

    /**** Assignment Change functions ****/

    /**Changes the token assignment, according to the following logic
     *
     * State 0 - State Identification
     *      if this is a new token update...
     *          if the token has no chunkIdx, state 1
     *          if token is in its own chunk, state 2
     *          if token is same chunk as left, state 3
     *          if token is same chunk as right, state 4
     * State 1 - Unassigned Token
     *      this token has no chunk. Assign it a chunk,
     *      an entity, and a new chain
     * State 2 - Singleton Chunk Token
     *      this token is in its own chunk. Drop the chunk.
     *      if there's an adjacent left chunk, attach and move to state 3
     *      elif there's an adjacent right chunk, attach and move to state 4
     *      else unassign and move to state 1
     * State 3 - Attached left
     *      this token is attached to a chunk to its left
     *      if it can attach to a chunk to its right, attach and move to state 4
     *      else unassign and move to state 1
     * State 4 - To-be-unassigned
     *      unassign this chunk
     */
    function changeTokenAssignment(captionIdx, tokenIdx)
    {
        var chunkIdx = null;
        if(tokenIdx in tokenTable_chunk[captionIdx]){
            chunkIdx = tokenTable_chunk[captionIdx][tokenIdx];
        }
        var rightTokenIdx = tokenIdx+1;
        var leftTokenIdx = tokenIdx-1;

        //STATE 0
        var state = 0;
        if(lastUpdate_type != "token" || lastUpdate_ID != captionIdx+"|"+tokenIdx){
            if(chunkIdx == null){
                state = 1;
            } else if(getChunkSize(captionIdx, chunkIdx) == 1){
                state = 2;
            } else if(leftTokenIdx in tokenTable_chunk[captionIdx] &&
                chunkIdx == tokenTable_chunk[captionIdx][leftTokenIdx]){
                state = 3;
            } else if(rightTokenIdx in tokenTable_chunk[captionIdx] &&
                chunkIdx == tokenTable_chunk[captionIdx][rightTokenIdx]){
                state = 4;
            } else {
                //if we're somehow in another state, act like the current state
                //is 4 so we can reassign the token and start from the top
                state = 4;
            }
        } else {
            state = lastUpdate_state;
        }

        //STATE 1
        if(state == 1) {
            //assign this token to a new chunk
            createNewChunk(captionIdx, tokenIdx, "NP");

            //move to state 2
            state = 2;
        }
        //STATE 2
        else if(state == 2) {
            //first, unassign the token
            unassignToken(captionIdx, tokenIdx);

            //if we can attach left, attach left
            if(getCanAttachLeft(captionIdx, tokenIdx)){
                tokenTable_chunk[captionIdx][tokenIdx] =
                    tokenTable_chunk[captionIdx][tokenIdx-1];
                state = 3;
            }
            //if we can attach right, attach right
            else if(getCanAttachRight(captionIdx, tokenIdx)){
                tokenTable_chunk[captionIdx][tokenIdx] =
                    tokenTable_chunk[captionIdx][tokenIdx+1];
                state = 4;
            }
            //otherwise we've just unassigned, so cycle back
            else{
                state = 1;
            }
        }
        //STATE 3
        else if(state == 3) {
            if(getCanAttachRight(captionIdx, tokenIdx)) {
                tokenTable_chunk[captionIdx][tokenIdx] =
                    tokenTable_chunk[captionIdx][tokenIdx+1];
                state = 4;
            } else {
                unassignToken(captionIdx, tokenIdx);
                state = 1;
            }
        }
        //STATE 4
        else if(state == 4) {
            unassignToken(captionIdx, tokenIdx);
            state = 1;
        }

        //set the last update to this
        lastUpdate_type = "token";
        lastUpdate_ID = captionIdx + "|" + tokenIdx;
        lastUpdate_state = state;
    }

    /**A helper for the changeTokenAssignment function, this
     * function removes the chunk association with this token
     *
     * @param tokenID - The token to unassign
     */
    function unassignToken(captionIdx, tokenIdx)
    {
        //get the (old) chunk and chain ID
        var oldChunkIdx = parseInt(tokenTable_chunk[captionIdx][tokenIdx]);
        var oldChunkType = chunkTable_type[captionIdx][oldChunkIdx];

        //delete this token's chunk association (and delete the chunk, if
        //this was the last token)
        delete tokenTable_chunk[captionIdx][tokenIdx];
        deleteChunk(captionIdx, oldChunkIdx);

        //we want to ensure that - in the split chunk case -
        //we actually split the chunk into two
        var tIdx;
        var leftChunkIdx = -1;
        tIdx = tokenIdx - 1;
        if(tIdx in tokenTable_chunk[captionIdx]){
            leftChunkIdx = parseInt(tokenTable_chunk[captionIdx][tIdx]);
        }
        var rightChunkIdx = -1;
        tIdx = tokenIdx + 1;
        if(tIdx in tokenTable_chunk[captionIdx]){
            rightChunkIdx = parseInt(tokenTable_chunk[captionIdx][tIdx]);
        }
        if(leftChunkIdx == oldChunkIdx &&
            oldChunkIdx == rightChunkIdx){
            var tokenArr = new Array();
            var cIdx;
            for(tIdx in tokenTable_chunk[captionIdx]){
                cIdx = tokenTable_chunk[captionIdx][tIdx];
                if(tIdx > tokenIdx && oldChunkIdx == cIdx){
                    tokenArr.push(tIdx);
                }
            }
            if(tokenArr.length > 0){
                var firstTokenIdx = tokenArr[0];
                createNewChunk(captionIdx, firstTokenIdx, oldChunkType);
                cIdx = tokenTable_chunk[captionIdx][firstTokenIdx];
                for(var i=1; i<tokenArr.length; i++){
                    tokenTable_chunk[captionIdx][tokenArr[i]] = cIdx;
                }
            }
        }
    }

    /**Updates that can occur on our data structures
     *
     * 1) Change the entityIdx->chainID association
     *      a) change entityIdx-chainID association
     *          if old chainSize < 1
     *              deleteChain(oldChainID)
     *      b) createNewChain
     * 2) Change the chunkIdx->type association
     *      change chunkIdx->type association
     *          if new type == NP
     *              createNewEntity
     *          else if old type == NP
     *              if entitySize < 1
     *                  deleteEntity
     * 3) Change the token -> chunkIdx association
     *      unassign token
     *      change token -> chunkIdx association
     *      createNewChunk
     *      deleteChunk
     *
     *  ---Behavior patterns---
     *  createNewChunk(captionIdx, tokenIdx)
     *      createNewEntity(captionIdx, chunkIdx)
     *          createNewChain(captionIdx, entityIdx)
     *
     *  deleteChunk(captionIdx, chunkIdx)
     *      if chunkIdx -> entityIdx && entitySize < 1
     *          deleteEntity(captionIdx, entityIdx)
     *              if chainSize < 1 (implicit && entityIdx -> chainID)
     *                  deleteChain(chainID)
     */
    function changeChunkTypeAssig(captionIdx, chunkIdx, type)
    {
        var oldType = chunkTable_type[captionIdx][chunkIdx];
        chunkTable_type[captionIdx][chunkIdx] = type;
        if(oldType == "NP"){
            var entityIdx = chunkTable_entity[captionIdx][chunkIdx];
            delete chunkTable_entity[captionIdx][chunkIdx];
            deleteEntity(captionIdx, entityIdx);
        } else if(type == "NP") {
            createNewEntity(captionIdx, chunkIdx);
        }
    }

    /**Creates new chunk from the specified captionIdx,tokenIdx
     */
    function createNewChunk(captionIdx, tokenIdx, chunkType)
    {
        var chunkIdx = getNextChunkIdx(captionIdx);
        tokenTable_chunk[captionIdx][tokenIdx] = chunkIdx;
        chunkTable_type[captionIdx][chunkIdx] = chunkType;
        if(chunkType == "NP"){
            createNewEntity(captionIdx, chunkIdx);
        }
    }

    /**Creates a new entity from the specified captionIdx,chunkIdx
     */
    function createNewEntity(captionIdx, chunkIdx)
    {
        var entityIdx = getNextEntityIdx(captionIdx);
        if(!isNaN(entityIdx)){
            chunkTable_entity[captionIdx][chunkIdx] = entityIdx;
            createNewChain(captionIdx, entityIdx);
        }
    }

    /**Create a new chain from the specified captionIdx,chunkIdx
     */
    function createNewChain(captionIdx, entityIdx)
    {
        var chainID = getNextChainID();
        var chainIdStr = "" + chainID;
        chainArr.push(chainIdStr);
        chainOrigDict[chainIdStr] = false;
        entityTable_chain[captionIdx][entityIdx] = chainIdStr;
        chainColorDispDict[chainID] = true;
        updateChainColors(chainIdStr);
        updateChainTableElement();
    }

    /**Deletes the specified captionIdx,chunkIdx if the chunk only has
     * one tokenIdx; if the chunk has an entity, drop the association
     * and try to delete the entity
     */
    function deleteChunk(captionIdx, chunkIdx)
    {
        if(getChunkSize(captionIdx, chunkIdx) < 1){
            delete chunkTable_type[captionIdx][chunkIdx];

            //if this chunk is attached to an entity, break the association
            if(chunkIdx in chunkTable_entity[captionIdx]){
                var entityIdx = chunkTable_entity[captionIdx][chunkIdx];
                delete chunkTable_entity[captionIdx][chunkIdx];
                deleteEntity(captionIdx, entityIdx);
            }
        }
    }

    /**Deletes the entity at captionIdx,entityIdx if the entity
     * has no chunks
     */
    function deleteEntity(captionIdx, entityIdx)
    {
        if(getEntitySize(captionIdx, entityIdx) < 1){
            var chainID = entityTable_chain[captionIdx][entityIdx];
            delete entityTable_chain[captionIdx][entityIdx];
            deleteChain(chainID);
        }
    }

    /**Deletes the chain with <b>chainID</b> if the chain has
     * no entities and isn't one of the originals
     */
    function deleteChain(chainID)
    {
        if(getChainSize(chainID) < 1 && !chainOrigDict[chainID]){
            chainArr.splice(chainArr.indexOf(chainID), 1);
            for(var boxID in boxChainArrDict){
                var idx = boxChainArrDict[boxID].indexOf(chainID);
                if(idx > -1){
                    boxChainArrDict[boxID].splice(idx, 1);
                }
            }
            delete chainOrigDict[chainID];
            delete chainColorDict[chainID];
            updateChainTableElement();
        }
    }

    /**** End Assignment Change functions ****/

    /**** Helper functions ****/

    /**Returns the max chain ID in use in this image, plus one
     */
    function getNextChainID()
    {
        var maxChainID = -1;
        for(var i=0; i<chainArr.length; i++)
        {
            var c = parseInt(chainArr[i]);
            if(c > maxChainID) {
                maxChainID = c;
            }
        }
        return maxChainID + 1;
    }

    /**Returns the max entity idx in use for this caption,
     * plus one
     */
    function getNextEntityIdx(captionIdx)
    {
        var maxEntityIdx = -1;
        for(var chunkIdx in chunkTable_entity[captionIdx]){
            var entityIdx = parseInt(chunkTable_entity[captionIdx][chunkIdx]);
            if(!isNaN(entityIdx)){
                if(entityIdx > maxEntityIdx){
                    maxEntityIdx = entityIdx;
                }
            }
        }
        return maxEntityIdx+1;
    }


    /**Returns the maximum chunk idx in use for
     * this caption, plus one
     */
    function getNextChunkIdx(captionIdx)
    {
        var maxChunkIdx = -1;
        for(var tokenIdx in tokenTable_chunk[captionIdx]) {
            var chunkIdx = parseInt(tokenTable_chunk[captionIdx][tokenIdx]);
            if(chunkIdx > maxChunkIdx) {
                maxChunkIdx = chunkIdx;
            }
        }
        return maxChunkIdx + 1;
    }

    /**Returns whether the given token can be attached
     * to an immediately adjacent (and different) chunk
     * to the right
     *
     * @param tokenID     - The token being attached
     * @returns {boolean} - Whether there is a different
     *                      chunk adjacent (right) to the token
     */
    function getCanAttachRight(captionIdx, tokenIdx)
    {
        var chunkIdx = tokenTable_chunk[captionIdx][tokenIdx];

        //determine if there even _is_ a right token
        var rightTokenIdx = tokenIdx+1;
        if(rightTokenIdx in tokenTable_chunk[captionIdx]){
            if(chunkIdx != tokenTable_chunk[captionIdx][rightTokenIdx]){
                return true;
            }
        }
        return false;
    }

    /**Returns whether the given token can be attached
     * to an immediately adjacent (and different) chunk
     * to the left
     *
     * @param tokenID     - The token being attached
     * @returns {boolean} - Whether there is a different
     *                      chunk adjacent (left) to the token
     */
    function getCanAttachLeft(captionIdx, tokenIdx)
    {
        var chunkIdx = tokenTable_chunk[captionIdx][tokenIdx];

        //determine if there even _is_ a left token
        var leftTokenIdx = tokenIdx-1;
        if(leftTokenIdx in tokenTable_chunk[captionIdx]){
            if(chunkIdx != tokenTable_chunk[captionIdx][leftTokenIdx]){
                return true;
            }
        }
        return false;
    }

    /**Returns the number of chunks in this chain
     *
     * @param chainID    - The chain to look for
     * @returns {number} - The number of chunks in the chain
     */
    function getChainSize(chainID)
    {
        var chainSize = 0;
        for(var captionIdx in entityTable_chain)
        {
            for(var entityIdx in entityTable_chain[captionIdx])
            {
                if(entityTable_chain[captionIdx][entityIdx] == chainID)
                {
                    chainSize++;
                }
            }
        }
        return chainSize;
    }

    /**Returns the number of tokens in the chunk
     *
     * @param chunkIdx - The chunk to look for
     * @returns {number} - The number of tokens in the chunk
     */
    function getChunkSize(captionIdx, chunkIdx)
    {
        var chunkSize = 0;
        for (var tokenIndex in tokenTable_chunk[captionIdx]) {
            if(tokenTable_chunk[captionIdx][tokenIndex] == chunkIdx) {
                chunkSize++;
            }
        }
        return chunkSize;
    }

    /**Returns the number of chunks in the entity
     */
    function getEntitySize(captionIdx, entityIdx)
    {
        var entitySize = 0;
        for(var chunkIdx in chunkTable_entity[captionIdx]){
            if(chunkTable_entity[captionIdx][chunkIdx] == entityIdx){
                entitySize++;
            }
        }
        return entitySize;
    }

    /**** End Helper functions ****/

    function setEndTimeStamp()
    {
        document.getElementById("hdn:duration").value =
            Math.floor(Date.now() / 1000) - startTS;
    }
</script>


<form method="post" action="">
    <!-- If I need to call JS on submit, don't foget the 'onsubmit=func()' attribute-->
    <input type="hidden" name="hdn:currentImg" value="<?php global $imgID; echo $imgID;?>"/>
    <input type="hidden" id="hdn:origChainDict" name="hdn:origChainDict" value="<?php echo getChainOrigDictStr();?>"/>
    <input type="hidden" id="hdn:duration" name="hdn:duration" value="0"/>
    <table>
        <tr>
            <td>
                <table>
                    <tr>
                        <td>
                            <!--We're going to draw the image on the canvas so we can then draw
                                boxes on top of it. Why do we need the tag if we're not going
                                to display it here (we draw it with JS)? No clue! But this works,
                                and the alternative didn't.-->
                            <img src="<?php echo getImgSrc();?>" id="img" style="display:none"/>
                            <canvas id="canvas" width="<?php echo getCanvasWidth();?>"
                                    height="<?php global $height; echo $height;?>"></canvas>
                        </td>
                        <td style="vertical-align: top" id="td:captionCell" class="captionText" colspan=2>
                            <!--This cell will be populated automatically via printCaptions()-->
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
        <tr>
            <td width="100%">
                <table width="100%">
                    <tr>
                        <td width="70%">
                            <table>
                                <tr>
                                    <td align="left">
                                        <table>
                                            <tr>
                                                <td align="left">
                                                    <input type="button" value="Toggle Boxes" onclick="toggleBoxes()">
                                                </td>
                                                <td align="right">
                                                    <input type="button" value="Toggle Chain Colors" onclick="toggleChainColors()">
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <table id="tbl:chainTable" class="interfaceText" style="border: 1px solid black;">
                                            <!--This cell will be populated automatically via updateChainTableElement()-->
                                            <!--UPDATE: empty row is needed when this table is grabbed by JS because... reasons?-->
                                            <tr><td></td></tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                        <td width="30%" style="vertical-align: top; horiz-align: right">
                            <table width="100%">
                                <tr>
                                    <td>
                                        <table style="padding:5px">
                                            <tr>
                                                <td class="interfaceText" colspan=2>
                                                    Image <em><?php global $imgID; echo $imgID;?></em>
                                                </td>
                                            </tr>
                                            <?php echo getModeHtm();?>
                                        </table>
                                        <hr/>
                                    </td>
                                </tr>
                                <tr><td class="interfaceText">Annotator Comments</td></tr>
                                <tr>
                                    <td width="100%">
                                        <textarea rows="4" name="txt:comments" id="txt:comments" disabled>
                                            <?php global $reviewComments; echo $reviewComments; ?>
                                        </textarea>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</form>
</body>
</html>