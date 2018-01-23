<?php
session_start();

if(!isset($_SESSION['servername'])){
    header('location: imgCleanup_login.php');
}

//init our global vars
$servername = $_SESSION['servername'];
$imgWebRoot = $_SESSION['imgWebRoot'];
$userTier = $_SESSION['tier'];

$debug=false;
$imgID="";
$prevImgStr="";
$debugStr="";
$viewStudent=false;
$verificationImg=false;
$chunkOnly=false;

$user=null;
if(isset($_SESSION['usr'])){
    $user = $_SESSION['usr'];
} else {
    //if the user isn't set at the session, we're debugging
    $debug = true;
}

if(isset($_GET['student'])) {
    $viewStudent = true;
}
if(isset($_GET['chunkOnly'])) {
    $chunkOnly=true;
}

//grab the debug query string, if present
if(isset($_GET["debug"])) {
    $debug = true;
    $servername='engr-cpanel-mysql.engr.illinois.edu';
}

//create a DB conn
$conn = new mysqli($servername,$_SESSION['username'],
    $_SESSION['password'], $_SESSION['database']);
if($conn->connect_error){
    die("Connection failed: ".$conn->connect_error);
}

//if we got here via the back button,
//get the previous image in the queue
if(isset($_POST['btn:sub_reset'])) {
    $imgID = $_POST['hdn:currentImg'];
} else {
    //if we got here via the next button, update the DB
    //with the last page's info
    if(isset($_POST['btn:sub_next'])) {
        updateTables();
    } else if (isset($_POST['btn:sub_quit'])) {
        updateTables();
        header('location: imgCleanup_logout.php');
    }

    //get the specified image, if one was specified
    if(isset($_GET["img"])) {
        $imgID = $_GET["img"];
    } else {
        //pull the next image off the DB's queue
        while($imgID == ""){
            $imgID = getNextImgID();
        }
    }
}

$reviewFlag = 1;
$query_reviewFlag = "SELECT reviewed FROM img WHERE img_id='".$imgID."';";

$result_flag = $conn->query($query_reviewFlag);
if($result_flag->num_rows > 0){
    while($row = $result_flag->fetch_assoc()) {
        $reviewFlag = $row['reviewed'];
    }
}

//get the token table for that image
//UPDATE: if the image has been reviewed before
//then we want the 'reviewed' columns, rather than
//the originals
$tokenTable = array();
$query_token = 'SELECT caption_idx, token_idx, token, '.
    'chunk_idx, chunk_type, entity_idx, chain_id, '.
    'chunk_idx_reviewed, chunk_type_reviewed, '.
    'entity_idx_reviewed, chain_id_reviewed, '.
    'chunk_idx_student_reviewed, chunk_type_student_reviewed, '.
    'entity_idx_student_reviewed, chain_id_student_reviewed, '.
    'reviewed '.
    'FROM token '.
    'WHERE img_id='.$imgID.';';
$result_token = $conn->query($query_token);
if($result_token->num_rows > 0)
{
    while($row = $result_token->fetch_assoc())
    {
        $captionIdx = $row["caption_idx"];
        $tokenIdx = $row["token_idx"];
        $token = $row["token"];
        if($token == ';'){
            $token = "[SEMI]";
        } else if($token == '#') {
            $token = "[HASH]";
        } else if($token == '|'){
            $token = "[PIPE]";
        }
        $chunkIdx = $row["chunk_idx"];
        $chunkType = $row["chunk_type"];
        $entityIdx = $row["entity_idx"];
        $chainID = $row["chain_id"];
        $chunkIdx_reviewed = $row["chunk_idx_reviewed"];
        $chunkType_reviewed = $row["chunk_type_reviewed"];
        $entityIdx_reviewed = $row["entity_idx_reviewed"];
        $chainID_reviewed = $row["chain_id_reviewed"];
        $chunkIdx_student_reviewed = $row["chunk_idx_student_reviewed"];
        $chunkType_student_reviewed = $row["chunk_type_student_reviewed"];
        $entityIdx_student_reviewed = $row["entity_idx_student_reviewed"];
        $chainID_student_reviewed = $row["chain_id_student_reviewed"];
        $reviewed = $row["reviewed"];
        $hasBeenReviewed = $reviewed > 0 || $reviewed != "0";

        //x of y constructions that haven't been reviewed have their
        //new entity indices in the reviewed column so if we see a non-null
        //value, use it
        if(!$hasBeenReviewed && $entityIdx_reviewed != NULL) {
            $entityIdx = $entityIdx_reviewed;
        }

        //only show reviewed columns if this is _not_ a verification img
        if($hasBeenReviewed && !$verificationImg) {
            $chunkIdx = $chunkIdx_reviewed;
            $chunkType = $chunkType_reviewed;
            $chainID = $chainID_reviewed;
            $entityIdx = $entityIdx_reviewed;

            /*
            //if the student flag is enabled, show their annotations
            if($viewStudent) {
                $chunkIdx = $chunkIdx_student_reviewed;
                $chunkType = $chunkType_student_reviewed;
                $chainID = $chainID_student_reviewed;
                $entityIdx = $entityIdx_student_reviewed;
            }*/
            if($reviewFlag == 2 || $reviewFlag == "2"){
                $chunkIdx = $chunkIdx_student_reviewed;
                $chunkType = $chunkType_student_reviewed;
                $chainID = $chainID_student_reviewed;
                $entityIdx = $entityIdx_student_reviewed;
            }
        }

        //replace the original placeholders with
        //their reviewed counterparts, if we're not using the origin
        if(!$useOrigin) {
            //x of Y constructions have their new entity indices in
            //the reviewed column, so if we see a non-null value, use it
            if($entityIdx_reviewed !== NULL) {
                $entityIdx = $entityIdx_reviewed;
            }

            //otherwise, use reviewed values if any of them have been set
            if ($reviewed == 1 || $reviewed == "1") {
            }
        }

        //populate this row in our token table
        if(!array_key_exists($captionIdx, $tokenTable))
        {
            $tokenTable[$captionIdx] = array();
        }
        $tokenTable[$captionIdx][$tokenIdx] =
            $token.";".$chunkIdx.";".$chunkType.";".
            $entityIdx.";".$chainID;
    }
}

//get all boxes for this image
$query_box = 'SELECT box_id, x_min, x_max, y_min, y_max '.
    'FROM box '.
    'WHERE img_id='.$imgID.';';
$result_box = $conn->query($query_box);
$boxCoordDict = array();
if($result_box->num_rows > 0)
{
    while($row = $result_box->fetch_assoc())
    {
        $boxID = $row["box_id"];
        $xMin = $row["x_min"];
        $xMax = $row["x_max"];
        $yMin = $row["y_min"];
        $yMax = $row["y_max"];
        $boxCoordDict[$boxID] = $xMin.";".$xMax.";".$yMin.";".$yMax;
    }
}

//get the chains for this image so we can easily identify which are originals
$query_chain = 'SELECT chain_id, is_orig '.
    'FROM chain '.
    'WHERE img_id='.$imgID.';';
$result_chain = $conn->query($query_chain);
$chainOrigDict = array();
if($result_chain->num_rows > 0) {
    while($row = $result_chain->fetch_assoc()){
        $chainID = $row['chain_id'];
        $isOrig = $row['is_orig'];
        $chainOrigDict[$chainID] = $isOrig;
    }
}

//get the associations between boxes and chains
$query_box_chain = 'SELECT box_id, chain_id '.
    'FROM box_chain '.
    'WHERE img_id='.$imgID.';';
$result_box_chain = $conn->query($query_box_chain);
$boxChainTable = array();
if($result_box_chain->num_rows > 0)
{
    while($row = $result_box_chain->fetch_assoc())
    {
        $boxID = $row["box_id"];
        $chainID = $row["chain_id"];
        if(!array_key_exists($boxID, $boxChainTable))
        {
            $boxChainTable[$boxID] = array();
        }
        array_push($boxChainTable[$boxID], $chainID);
    }
}

//get the image dimensions and previously reviewed states
$query_img = 'SELECT width, height, needs_additional, '.
    'review_comments, mischunk_caps '.
    'FROM img '.
    'WHERE img_id='.$imgID.';';
$result_img = $conn->query($query_img);
$width=-1;
$height=-1;
$needsAdditional=false;
$reviewComments='';
$mischunkCapStr='';
if($result_img->num_rows > 0)
{
    while($row = $result_img->fetch_assoc())
    {
        $width = $row["width"];
        $height = $row["height"];
        if(!$verificationImg){
            $needsAdditional = $row["needs_additional"] == 1;
            $reviewComments = $row["review_comments"];
        }
        if($chunkOnly){
            $mischunkCapStr = $row["mischunk_caps"];
        }
    }
}

//get the caption typo / irrelevant checkboxes
$query_caption = 'SELECT caption_idx, reviewed_typo, '.
    'reviewed_irrelevant, reviewed_mischunk '.
    'FROM caption '.
    'WHERE img_id='.$imgID.';';
$captionTypoArr = array();
$captionIrrelevantArr = array();
$captionMischunkArr = array();
$result_caption = $conn->query($query_caption);
if($result_caption->num_rows > 0)
{
    while($row = $result_caption->fetch_assoc())
    {
        $captionIdx = $row["caption_idx"];
        $reviewed_typo = $row["reviewed_typo"];
        $reviewed_irrelevant = $row["reviewed_irrelevant"];
        $reviewed_mischunk = $row["reviewed_mischunk"];
        if($verificationImg){
            $reviewed_typo = 0;
            $reviewed_irrelevant = 0;
            $reviewed_mischunk = 0;
        }
        $captionTypoArr[$captionIdx] = $reviewed_typo;
        $captionIrrelevantArr[$captionIdx] = $reviewed_irrelevant;
        $captionMischunkArr[$captionIdx] = $reviewed_mischunk;
    }
}


//dont forget to close the conn
$conn->close();


function getNeedsAdditionalStr()
{
    global $needsAdditional;
    if($needsAdditional)
        return "checked";
    else
        return "";
}

function getReviewCommentStr()
{
    global $reviewComments;
    if($reviewComments != '')
        return $reviewComments;
    else
        return "";
}

function getCaptionTypoArrStr()
{
    global $captionTypoArr;
    $captionTypoStr = "";
    for($i=0; $i < sizeof($captionTypoArr); $i++){
        $captionTypoStr .= $captionTypoArr[$i];
        if($i < sizeof($captionTypoArr)-1) {
            $captionTypoStr .= "|";
        }
    }
    return $captionTypoStr;
}

function getCaptionIrrelevantArrStr()
{
    global $captionIrrelevantArr;
    $captionIrrelevantStr = "";
    for($i=0; $i < sizeof($captionIrrelevantArr); $i++){
        $captionIrrelevantStr .= $captionIrrelevantArr[$i];
        if($i < sizeof($captionIrrelevantArr)-1) {
            $captionIrrelevantStr .= "|";
        }
    }
    return $captionIrrelevantStr;
}

function getCaptionMischunkArrStr()
{
    global $captionMischunkArr;
    $captionMischunkStr = "";
    for($i=0; $i < sizeof($captionMischunkArr); $i++){
        $captionMischunkStr .= $captionMischunkArr[$i];
        if($i < sizeof($captionMischunkArr)-1){
            $captionMischunkStr .= "|";
        }
    }
    return $captionMischunkStr;
}

/**Returns the next image from the <img> table,
 * off the review queue
 *
 * @return string - the next image ID
 */
function getNextImgID()
{
    global $conn, $userTier, $user, $verificationImg, $chunkOnly;

    //get the next as-yet unreviewed image
    $query_img = "SELECT img_id ".
                 "FROM img ";

    if($chunkOnly){
        $query_img .= 'WHERE review_order_orig>0 AND '
                      .'review_order_orig<4001 AND '
                      .'reviewed=0 AND '
                      .'mischunk_caps IS NOT NULL AND '
                      .'num_chains<11 '
                      .'LIMIT 1';

        $result = $conn->query($query_img);
        $imgID = "";
        if($result->num_rows > 0) {
            while($row = $result->fetch_assoc()) {
                $imgID = $row['img_id'];
            }
        }
        return $imgID;
    } else if($user == 'ccervan2'){

        /*
        $query_img = 'SELECT img_id FROM img '.
                     'WHERE reviewer_id=\'ccervan2\' '.
                     'AND anno_ts>\'2016-06-01\' AND anno_ts<\'2016-06-09\' '.
                     'ORDER BY review_order_orig DESC LIMIT 1';
        */
        /*
        $query_img = 'SELECT img.img_id FROM img JOIN caption ON '.
                     'img.img_id=caption.img_id WHERE caption.reviewed_mischunk=1 '.
                     'AND img.reviewed=2 AND caption.reviewed_irrelevant=0 '.
                     'ORDER BY img.review_order_orig DESC LIMIT 1;';
        */
        //$query_img = 'SELECT DISTINCT img_id FROM token WHERE caption_idx>4 AND reviewed=0 LIMIT 1';
        $query_img = 'SELECT img_id FROM img WHERE (cross_val=0 OR cross_val=2) AND '.
                     'reviewed=0 ORDER BY review_order_orig DESC LIMIT 1';

        $result = $conn->query($query_img);
        $imgID = "";
        if($result->num_rows > 0) {
            while($row = $result->fetch_assoc()) {
                $imgID = $row['img_id'];
            }
        }
        return $imgID;
    }

    //if this is a reviewer, determine if they should
    //get a verification img
    //UPDATE: only specific reviewers get verification images;
    //        the rest get (random) dups
    $dupImg = false;
    if($user == 'jkalfor2'){
        //get the total number of images this user has reviewed
        //and the number of verified images they've reviewed
        $query_user = 'SELECT verified_imgs, reviewed_imgs '.
            'FROM reviewers '.
            'WHERE reviewer_id="'.$user.'"';
        $result_user = $conn->query($query_user);
        $numVerified = 0;
        $numTotal = 0;
        if($result_user->num_rows > 0) {
            while($row = $result_user->fetch_assoc()) {
                $numVerified = $row['verified_imgs'];
                $numTotal = $row['reviewed_imgs'];
            }
        }

        $n = 10;
        $t_less_nv = max(0, $numTotal - ($numVerified * $n));
        $thresh = pow($t_less_nv / $n, exp(1));
        $r = (float)rand()/(float)getrandmax();

        if($r <= $thresh){
            $query_img .= "WHERE reviewed IN (1,3) AND num_chains < 11 ";
            $verificationImg = true;
        } else {
            $query_img .= "WHERE review_order>0 AND review_order<4001 AND num_chains < 11 ";
        }
    } else {
        $r = (float)rand()/(float)getrandmax();
        if($r<=.10){
            $dupImg=true;
            $query_img .= "WHERE reviewed=2 AND reviewer_id <> '".$user."' ";
        } else {
            $query_img .= "WHERE reviewed=0 AND review_order>0 AND review_order < 4001 AND num_chains < 11 ";
        }
    }

    $query_img .= "ORDER BY ";
    if($verificationImg || $dupImg){
        $query_img .= "RAND() ";
    } else {
        $query_img .= "review_order DESC ";
    }
    $query_img .= "LIMIT 1";

    $result = $conn->query($query_img);
    $imgID = "";
    if($result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            $imgID = $row['img_id'];
        }
    }

    //and remove it from the top of the queue
    //with special code -2
    if(!$verificationImg && !$chunkOnly && $imgID != ""){
        $imgQuery = "UPDATE img ".
            "SET review_order=-2";
        $imgQuery .= " WHERE img_id=" . $imgID . ";";
        if($conn->query($imgQuery) === FALSE) {
            die('Failed to update DB with <br/>'.$imgQuery);
        }
    }

    //return the imgID
    return $imgID;
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
    for($i=0; $i < sizeof($tokenTable); $i++)
    {
        for($j=0; $j < sizeof($tokenTable[$i]); $j++)
        {
            $tokenTableStr .= $tokenTable[$i][$j];
            if($j < sizeof($tokenTable[$i])-1)
            {
                $tokenTableStr .= "#";
            }
        }
        if($i < sizeof($tokenTable)-1)
        {
            $tokenTableStr .= "|";
        }
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
    {
        $boxArrStr .= $boxID."#".$coordStr."|";
    }
    return $boxArrStr;
}

function getChainOrigDictStr()
{
    global $chainOrigDict;
    $chainArrStr = "";
    foreach($chainOrigDict as $chainID => $isOrig){
        $chainArrStr .= $chainID.'#'.$isOrig."|";
    }
    return $chainArrStr;
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
    foreach($boxChainTable as $boxID => $chainIDArr)
    {
        $boxChainTableStr .= $boxID."#";
        for($i=0; $i < sizeof($chainIDArr); $i++)
        {
            $boxChainTableStr .= $chainIDArr[$i] . ";";
        }
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

/**Updates the tables with the previous page's changes,
 * all contained in the POST object
 */
function updateTables()
{
    global $conn, $debugStr, $user, $debug, $userTier, $chunkOnly;

    if($debug)
        return;

    //get the then-current image id from the hidden tag
    $prevImgID = $_POST['hdn:currentImg'];

    //get the previous duration
    $prevDuration = $_POST['hdn:duration'];

    //get the then-current origChainDict
    $origChainDict = array();
    $origChainDictStr = $_POST['hdn:origChainDict'];
    $origChainDictStrArr = explode("|", $origChainDictStr);
    foreach($origChainDictStrArr as $chainOrigStr){
        $chainOrigStrArr = explode("#", $chainOrigStr);
        $origChainDict[$chainOrigStrArr[0]] = $chainOrigStrArr[1];
    }

    //get the box_chain insert queries
    $boxChainInsertQueryArr = array();
    if(array_key_exists("chkbx:chainTable", $_POST)) {
        $chainTable = $_POST["chkbx:chainTable"];
        foreach($chainTable as $boxID => $chainArr) {
            foreach($chainArr as $chainID => $chkbx) {
                //if this chain ID isn't one of the originals, add
                //an update or insert query
                if($origChainDict[$chainID] != '1' && $chkbx == "on"){
                    $query = "INSERT INTO box_chain (img_id, ".
                        "box_id, chain_id) ".
                        "VALUES ('".$prevImgID."', ".
                        $boxID.", '".$chainID."') ".
                        "ON DUPLICATE KEY UPDATE ".
                        "img_id='".$prevImgID."', ".
                        "box_id=".$boxID.", chain_id='".$chainID."';";
                    $debugStr.=$query;
                    $debugStr.="\n";
                    array_push($boxChainInsertQueryArr, $query);
                }
            }
        }
    }

    if(sizeof($boxChainInsertQueryArr) > 0) {
        //insert the new rows
        for($i=0; $i<sizeOf($boxChainInsertQueryArr); $i++) {
            $debugStr.=$boxChainInsertQueryArr[$i];
            $debugStr.="\n";
            if($conn->query($boxChainInsertQueryArr[$i]) === FALSE) {
                die('Failed to update DB with <br/>'.$boxChainInsertQueryArr[$i]);
            }
        }
    }

    //get the token update queries
    $tokenUpdateQueryArr = array();
    if(array_key_exists("hdn:token", $_POST)) {
        $tokenTable = $_POST["hdn:token"];
        foreach($tokenTable as $captionIdx => $tokenArr) {
            foreach($tokenArr as $tokenIdx => $tokenAssig) {
                //split the token assignment into chunk and chain indices
                $tokenAssigArr = explode(";", $tokenAssig);
                $chunkIdx = null;
                $chainID = null;
                $chunkType = null;
                $entityIdx = null;
                foreach ($tokenAssigArr as $assig) {
                    $assigArr = explode(":", $assig);
                    if ($assigArr[0] == "chunkIdx") {
                        $chunkIdx = $assigArr[1];
                    } elseif ($assigArr[0] == "chainID") {
                        $chainID = $assigArr[1];
                    } elseif($assigArr[0] == "chunkType") {
                        $chunkType = $assigArr[1];
                    } elseif($assigArr[0] == "entityIdx") {
                        $entityIdx = $assigArr[1];
                    }
                }


                $query = "UPDATE token ";
                $query .= "SET ";
                $chunkIdxCol = "chunk_idx_reviewed";
                $chunkTypeCol = "chunk_type_reviewed";
                $entityIdxCol = "entity_idx_reviewed";
                $chainIdCol = "chain_id_reviewed";
                if($userTier < 3){
                    $chunkIdxCol = "chunk_idx_student_reviewed";
                    $chunkTypeCol = "chunk_type_student_reviewed";
                    $entityIdxCol = "entity_idx_student_reviewed";
                    $chainIdCol = "chain_id_student_reviewed";
                }

                $query .= $chunkIdxCol . "=";
                if ($chunkIdx == "null" || $chunkIdx == "" || $chunkIdx == "undefined")
                {
                    $query .= "NULL";
                }
                else
                {
                    $query .= "'".$chunkIdx."'";
                }
                $query .= ", ";
                $query .= $chainIdCol . "=";
                if($chainID == "null" || $chainID == "" || $chainID == "undefined")
                {
                    $query .= "NULL";
                }
                else
                {
                    $query .= "'".$chainID."'";
                }
                $query .= ", ";
                $query .= $chunkTypeCol . "=";
                if($chunkType == "null" || $chunkType == "" || $chunkType == "undefined")
                {
                    $query .= "NULL";
                }
                else
                {
                    $query .= "'".$chunkType."'";
                }
                $query .= ", ";
                $query .= $entityIdxCol . "=";
                if($entityIdx == "null" || $entityIdx == "" || $entityIdx == "undefined")
                {
                    $query .= "NULL";
                }
                else
                {
                    $query .= "'".$entityIdx."'";
                }
                $query .= ", ";
                $query .= "reviewed=";
                if($userTier < 3){
                    $query .= "2 ";
                } else {
                    $query .= "1 ";
                }
                $query .= " ";
                $query .= "WHERE img_id='".$prevImgID.
                    "' AND caption_idx='".$captionIdx.
                    "' AND token_idx='".$tokenIdx."';";
                array_push($tokenUpdateQueryArr, $query);
            }
        }
    }
    if(sizeof($tokenUpdateQueryArr) > 0)
    {
        //update all the token assignments
        for($i=0; $i<sizeOf($tokenUpdateQueryArr); $i++)
        {
            $debugStr.=$tokenUpdateQueryArr[$i];
            $debugStr.="\n";
            if($conn->query($tokenUpdateQueryArr[$i]) === FALSE)
            {
                die('Failed to update DB with <br/>'.$tokenUpdateQueryArr[$i]);
            }
        }
    }

    //insert everything into our new token_anno table for inter-annotator agreement
    $tokenAnnoUpdateQueryArr = array();
    if(array_key_exists("hdn:token", $_POST) && $userTier < 3) {
        $tokenTable = $_POST["hdn:token"];
        foreach($tokenTable as $captionIdx => $tokenArr) {
            foreach($tokenArr as $tokenIdx => $tokenAssig) {
                //split the token assignment into chunk and chain indices
                $tokenAssigArr = explode(";", $tokenAssig);
                $chunkIdx = null;
                $chainID = null;
                $chunkType = null;
                $entityIdx = null;
                foreach ($tokenAssigArr as $assig) {
                    $assigArr = explode(":", $assig);
                    if ($assigArr[0] == "chunkIdx") {
                        $chunkIdx = $assigArr[1];
                    } elseif ($assigArr[0] == "chainID") {
                        $chainID = $assigArr[1];
                    } elseif($assigArr[0] == "chunkType") {
                        $chunkType = $assigArr[1];
                    } elseif($assigArr[0] == "entityIdx") {
                        $entityIdx = $assigArr[1];
                    }
                }


                $chunkIdxCol = "chunk_idx_student_reviewed";
                $chunkTypeCol = "chunk_type_student_reviewed";
                $entityIdxCol = "entity_idx_student_reviewed";
                $chainIdCol = "chain_id_student_reviewed";


                $query = "INSERT INTO token_anno(";
                $query .= "img_id, caption_idx, token_idx, reviewer_id, ";
                $query .= $chunkIdxCol . ", ";
                $query .= $chunkTypeCol . ", ";
                $query .= $entityIdxCol . ", ";
                $query .= $chainIdCol . ")";
                $query .= " VALUES (";
                $query .= "'".$prevImgID."', ";
                $query .= $captionIdx.", ";
                $query .= $tokenIdx.", ";
                $query .= "'".$user."', ";
                $chunkIdxVal = "NULL";
                if ($chunkIdx != "null" && $chunkIdx != "" && $chunkIdx != "undefined" && $chunkIdx != "NaN")
                {
                    $chunkIdxVal = "'".$chunkIdx."'";
                }
                $query .= $chunkIdxVal . ", ";
                $chunkTypeVal = "NULL";
                if($chunkType != "null" && $chunkType != "" && $chunkType != "undefined")
                {
                    $chunkTypeVal = "'".$chunkType."'";
                }
                $query .= $chunkTypeVal . ", ";
                $entityIdxVal = "NULL";
                if($entityIdx != "null" && $entityIdx != "" && $entityIdx != "undefined" && $entityIdx != "NaN")
                {
                    $entityIdxVal = "'".$entityIdx."'";
                }
                $query .= $entityIdxVal . ", ";
                $chainIDVal = "NULL";
                if($chainID != "null" && $chainID != "" && $chainID != "undefined")
                {
                    $chainIDVal = "'".$chainID."'";
                }
                $query .= $chainIDVal . ") ";
                $query .= "ON DUPLICATE KEY UPDATE ";
                $query .= $chunkIdxCol . "=".$chunkIdxVal .", ";
                $query .= $chunkTypeCol . "=".$chunkTypeVal .", ";
                $query .= $entityIdxCol . "=".$entityIdxVal .", ";
                $query .= $chainIdCol . "=".$chainIDVal;
                $query .= ";";
                array_push($tokenAnnoUpdateQueryArr, $query);
            }
        }
    }
    if(sizeof($tokenAnnoUpdateQueryArr) > 0)
    {
        //update all the token assignments
        for($i=0; $i<sizeOf($tokenAnnoUpdateQueryArr); $i++)
        {
            $debugStr.=$tokenAnnoUpdateQueryArr[$i];
            $debugStr.="\n";
            if($conn->query($tokenAnnoUpdateQueryArr[$i]) === FALSE)
            {
                die('Failed to update DB with <br/>'.$tokenAnnoUpdateQueryArr[$i]);
            }
        }
    }

    //check if that last image had already been verified
    $verifImg = false;
    $query_img = "SELECT reviewed FROM img WHERE img_id='".$prevImgID."'";
    $result = $conn->query($query_img);
    if($result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            $verifImg = $row['reviewed'] == 1;
        }
    }

    //get the caption update queries
    $captionUpdateQueryArr = array();
    $irrCaptionCheckArr = [0, 0, 0, 0, 0];
    $typoCaptionCheckArr = [0, 0, 0, 0, 0];
    $mischunkCaptionCheckArr = [0, 0, 0, 0, 0];
    if(array_key_exists("chkbx:irrCaption", $_POST)) {
        $irrCaptionArr = $_POST["chkbx:irrCaption"];
        foreach ($irrCaptionArr as $captionIdx => $checked) {
            if($checked == "on") {
                $irrCaptionCheckArr[$captionIdx] = 1;
            }
        }
    }
    if(array_key_exists("chkbx:typoPresent", $_POST)) {
        $typoPresentArr = $_POST["chkbx:typoPresent"];
        foreach ($typoPresentArr as $captionIdx => $checked) {
            if($checked == "on") {
                $typoCaptionCheckArr[$captionIdx] = 1;
            }
        }
    }
    if(array_key_exists("chbx:mischunkedCaption", $_POST)) {
        $mischunkPresentArr = $_POST["chbx:mischunkedCaption"];
        foreach ($mischunkPresentArr as $captionIdx => $checked) {
            if($checked == "on") {
                $mischunkCaptionCheckArr[$captionIdx] = 1;
            }
        }
    }

    if(!$verifImg){
        for($i=0; $i < 5; $i++) {
            $query = "UPDATE caption SET ".
                "reviewed_irrelevant=".$irrCaptionCheckArr[$i].", ".
                "reviewed_typo=".$typoCaptionCheckArr[$i].", ".
                "reviewed_mischunk=".$mischunkCaptionCheckArr[$i].", ".
                "reviewed=1 ".
                "WHERE img_id='".$prevImgID."' ".
                "AND caption_idx=".$i.";";
            $debugStr.=$query;
            $debugStr.="\n";
            if($conn->query($query) === FALSE) {
                die('Failed to update DB with <br/>'.$captionUpdateQueryArr[$i]);
            }
        }
    }


    //finally, update the img table to reflect that
    //this image has been reviewed (and whether it needs
    //additional review)
    if($chunkOnly) {
        $imgQuery = "UPDATE img SET mischunk_caps=NULL ".
            "WHERE img_id=" . $prevImgID . ";";
        $debugStr .= $imgQuery;
        $debugStr .= "\n";
        if($conn->query($imgQuery) === FALSE)
        {
            die('Failed to update DB with <br/>'.$imgQuery);
        }
    } else {
        $imgQuery = "UPDATE img SET ";
        $imgQuery .= "reviewed=";
        if($userTier < 3){
            if($verifImg){
                $imgQuery .= "3, ";
            } else {
                $imgQuery .= "2, ";
            }
        } else {
            $imgQuery .= "1, ";
        }
        $checked = $_POST['chkbx:needsAdditional'];
        if(!$verifImg && $checked == "on"){
            $imgQuery .= "needs_additional=1, ";
        }
        $text = $_POST['txt:comments'];
        if($text != "")
        {
            $text = str_replace("\"", "'", $text);
            $text = str_replace(";", ":", $text);
            $text = str_replace("'", "''", $text);

            $imgQuery .= "review_comments=CONCAT('".$text."', review_comments), ";
        }
        $imgQuery .= "reviewer_id='".$user."', ";
        $imgQuery .= "anno_duration=".$prevDuration." ";
        $imgQuery .= "WHERE img_id=" . $prevImgID . ";";
        $debugStr .= $imgQuery;
        $debugStr .= "\n";
        if($conn->query($imgQuery) === FALSE)
        {
            die('Failed to update DB with <br/>'.$imgQuery);
        }
    }

    $reviewersQuery = "UPDATE reviewers SET ";
    $reviewersQuery .= "reviewed_imgs=reviewed_imgs+1";
    if($verifImg){
        $reviewersQuery .= ", verified_imgs=verified_imgs+1";
    }
    $reviewersQuery .= " WHERE reviewer_id='".$user."'";
    if($conn->query($reviewersQuery) === FALSE)
    {
        die('Failed to update DB with <br/>'.$reviewersQuery);
    }

    //update our session vars
    $_SESSION['reviewedImgs'] += 1;
    $_SESSION['totalTime'] += $prevDuration;
}

function getModeHtm()
{
    global $userTier;
    $modeHtm = '';
    if($userTier == "3" || $$userTier == 3){
        $modeHtm = '<tr><td id="td:modeIcon" style="width:35px"></td>'.
            '<td class="interfaceText" id="td:modeLabel"> </td>'.
            '</tr><tr><td id="td:modeButton" colspan=2> </td>'.
            '</tr>';
    }
    return $modeHtm;
}

?>

<html>
<head>
    <link rel="stylesheet" type="text/css" href="imgCleanup_style.css">
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/font-awesome/4.4.0/css/font-awesome.min.css">
</head>
<body onload="init()">
<script type="text/javascript">
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

    //a debug flag for printing everything to the console
    var debug;

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

        debug = ('1' === '<?php global $debug; echo $debug;?>');
        if(debug){
            alert("WARNING: debug mode. No changes will be saved.");
        }

        //by default, set the next button to disabled
        reviewCheckHandler();

        //first, populate the token and box tables from the php str
        initTables();

        //init chain colors
        initChainColors();

        //if this is a chunking only image, set chunk mode to true
        if(mischunkCapsStr != ''){
            chunkMode = true;
        }

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
        mischunkCapsStr = "<?php global $mischunkCapStr; echo $mischunkCapStr;?>";

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
        captionHtm += "<tr><td></td><td style=\"width:30px\" " +
            "class=\"centeredTableCell\">" +
            "Irrelevant Caption</td>"+
            "<td class=\"centeredTableCell\">"+
            "Typo Present</td>"+
            "<td class=\"centeredTableCell\">"+
            "Contains Chunking Error</td>"+
            "</tr>";

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
                                captionHtm += " <sub onclick=\"entityChainClickHandler()\" "+
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
                                    "<sup onclick=\"chunkTypeClickHandler()\" "+
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
                            tokenID + "\" "+
                            "onclick=\"tokenClickHandler()\">";
                    }
                    captionHtm += text;
                    if(chunkMode) {
                        captionHtm += "</span>";
                    }
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
                            captionHtm += " <sub onclick=\"entityChainClickHandler()\" "+
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
                if(debug && (lastUpdateCaption < 0 || lastUpdateCaption == captionIdx))
                {
                    console.log("[printCaptions] captionIdx: " + captionIdx + " ---> " + debugStr);
                }

                var checkboxName;

                //add the irrelevant caption checkbox
                checkboxName = "chkbx:irrCaption[" + captionIdx + "]";
                captionHtm += "<td class=\"centeredTableCell\">";
                captionHtm += "<input type=\"checkbox\" ";
                captionHtm += "name=\""+checkboxName+"\" ";
                captionHtm += "id=\""+checkboxName+"\" ";

                //if this is the first print captions, check the stored checkbox values
                if(document.getElementById(checkboxName) == null) {
                    if(captionIrrelevantStrArr[captionIdx] == '1'){
                        captionHtm += "checked ";
                    }
                } else {
                    if(document.getElementById(checkboxName).checked) {
                        captionHtm += "checked ";
                    }
                }
                captionHtm += "/>";
                captionHtm += "</td>";

                //add the typo present checkbox
                checkboxName = "chkbx:typoPresent[" + captionIdx + "]";
                captionHtm += "<td class=\"centeredTableCell\">";
                captionHtm += "<input type=\"checkbox\" ";
                captionHtm += "name=\""+checkboxName+"\" ";
                captionHtm += "id=\""+checkboxName+"\" ";
                if(document.getElementById(checkboxName) == null) {
                    if(captionTypoStrArr[captionIdx] == '1'){
                        captionHtm += "checked ";
                    }
                } else {
                    if(document.getElementById(checkboxName).checked) {
                        captionHtm += "checked ";
                    }
                }
                captionHtm += "/>";
                captionHtm += "</td>";

                //add the mischunk checkbox
                checkboxName = "chbx:mischunkedCaption[" + captionIdx + "]";
                captionHtm += "<td class=\"centeredTableCell\">";
                captionHtm += "<input type=\"checkbox\" ";
                captionHtm += "name=\"" + checkboxName + "\" ";
                captionHtm += "id=\"" + checkboxName + "\" ";
                if(document.getElementById(checkboxName) == null) {
                    if(captionMischunkStrArr[captionIdx] == '1'){
                        captionHtm += "checked ";
                    }
                } else {
                    if(document.getElementById(checkboxName).checked) {
                        captionHtm += "checked ";
                    }
                }
                captionHtm += "/>";
                captionHtm += "</td>";

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
                if(!foundBox) {
                    noBoxChainArr.push(chainID);
                    if(debug) {
                        console.log("[updateChainTableElement] No box found for [chain:" +
                            chainID + ";isOrig:"+chainOrigDict[chainID]+"] omitting from table");
                    }
                }
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
                if(!chainOrigDict[chainID]) {
                    chainTableHtm += "*";
                }
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
                    if(chainOrigDict[colID]){
                        chainTableHtm += "disabled ";
                    }
                    else {
                        //since this is a new checkbox, we also
                        //want to give it a name so php can find it
                        chainTableHtm += "name=\"chkbx:chainTable[" + boxID + "][" + colID + "]\" ";
                    }

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
        if(debug)
            console.log("[entityChainClickHandler] elem.id: " + elem.id);

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
            if(debug) {
                console.log("[entityChainClickHandler] assigning entity ("+
                    "capIdx:"+captionIdx+";entIdx:"+entityIdx+
                    ") to chain ("+chainArr[nextChainIdx] + ")");
            }
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


    /**Handles the image review checkbox events
     */
    function reviewCheckHandler()
    {
        //if the two explicit 'did you do the job' checkboxes are checked or if
        //the needs additional review is checked, the
        //next button is enabled. Otherwise it's disabled
        var nextButton = document.getElementById("btn:sub_next");
        var quitButton = document.getElementById("btn:sub_quit");
        var chunkingChecked = document.getElementById("chkbx:chunkingCorrect").checked;
        var corefChecked = document.getElementById("chkbx:corefCorrect").checked;
        var needsChecked = document.getElementById("chkbx:needsAdditional").checked;
        if((chunkingChecked && corefChecked) || needsChecked) {
            nextButton.disabled = false;
            quitButton.disabled = false;
        } else {
            nextButton.disabled = true;
            quitButton.disabled = true;
        }

        //update the icons to reflect the current state
        var chunkHtm = "<i style=\"font-size:2em;color:";
        if(chunkingChecked) {
            chunkHtm += "green;\" class=\"fa fa-check-circle fa-2x\"></i>";
        } else {
            chunkHtm += "darkgoldenrod;\" class=\"fa fa-question-circle fa-2x\"></i>";
        }
        document.getElementById("td:chunkIcon").innerHTML = chunkHtm;
        var corefHtm = "<i style=\"font-size:2em;color:";
        if(corefChecked) {
            corefHtm += "green;\" class=\"fa fa-check-circle fa-2x\"></i>";
        } else {
            corefHtm += "darkgoldenrod;\" class=\"fa fa-question-circle fa-2x\"></i>";
        }
        document.getElementById("td:corefIcon").innerHTML = corefHtm;
        var needsHtm = "";
        if(needsChecked) {
            needsHtm += "<span style=\"font-size:1em\" "+
                "class=\"fa-stack fa-lg\">"+
                "<i style=\"color:red;\" " +
                "class=\"fa fa-circle fa-stack-2x\"></i>"+
                "<i class=\"fa fa-flag "+
                "fa-stack-1x fa-inverse\"></i>"+
                "</span>";
        } else {
            needsHtm += "<i style=\"font-size:2em;"+
                "color:darkgoldenrod;\" "+
                "class=\"fa fa-question-circle fa-2x\"></i>";
        }
        document.getElementById("td:needsIcon").innerHTML = needsHtm;

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
        if(debug) {
            console.log("[changeTokenAssignment] current state: " + state);
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

        if(debug) {
            console.log("[changeTokenAssignment] moving to state: " + state);
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
        if(debug) {
            console.log("[createNewChunk] assigning token "+
                "[capIdx:" + captionIdx + ";tokenIdx:"+
                tokenIdx + "] to new chunk:" + chunkIdx);
        }
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
        if(debug) {
            console.log("[createNewEntity] assigning chunk "+
                "[capIdx:" + captionIdx + ";chunkIdx:"+
                chunkIdx + "] to new entity:" + entityIdx);
        }
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
        if(debug) {
            console.log("[createNewChain] assigning entity "+
                "[capIdx:" + captionIdx + ";entityIdx:"+
                entityIdx + "] to new chain:" + chainIdStr);
        }
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
            if(debug){
                console.log("[deleteChunk] deleting captionIdx:"+captionIdx+
                    ";chunkIdx:" + chunkIdx);
            }
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
            if(debug){
                console.log("[deleteEntity] deleting captionIdx:"+captionIdx+
                    ";entityIdx:" + entityIdx);
            }
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
            if(debug){
                console.log("[deleteChain] deleting chain: " + chainID);
            }
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
                                                    Reviewing Image <em><?php global $imgID; echo $imgID;?></em> in
                                                </td>
                                            </tr>modeH
                                        </table>
                                        <hr/>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <table style="padding:5px">
                                            <tr>
                                                <td id="td:chunkIcon" style="width:35px"></td>
                                                <td class="interfaceText">Chunking is correct</td>
                                                <td>
                                                    <input type="checkbox" checked id="chkbx:chunkingCorrect" onchange="reviewCheckHandler()"/>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td id="td:corefIcon"></td>
                                                <td class="interfaceText">Coreference is correct</td>
                                                <td>
                                                    <input type="checkbox" checked id="chkbx:corefCorrect" onchange="reviewCheckHandler()"/>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td id="td:needsIcon"></td>
                                                <td class="interfaceText">Image needs additional review</td>
                                                <td>
                                                    <input type="checkbox" name="chkbx:needsAdditional"
                                                        <?php echo getNeedsAdditionalStr();?>
                                                           id="chkbx:needsAdditional" onchange="reviewCheckHandler()"/>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td width="100%">
                                        <textarea rows="4" name="txt:comments" id="txt:comments" placeholder="Please enter review comments here"><?php global $reviewComments; echo $reviewComments; ?></textarea>
                                    </td>
                                </tr>
                                <tr>
                                    <td>
                                        <table cellpadding="10px">
                                            <tr>
                                                <td>
                                                    <input type="submit" onclick="return confirm('Are you sure you want to discard your work and reset this image?');"
                                                           name="btn:sub_reset" value="Reset Image"/>
                                                </td>
                                                <td>
                                                    <input type="submit" onclick=setEndTimeStamp() id="btn:sub_next" name="btn:sub_next" title="Both chunking and coref must be correct to enable"
                                                           value="Save and Continue"/>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td></td>
                                                <td>
                                                    <input type="submit" onclick="setEndTimeStamp()" id="btn:sub_quit" name="btn:sub_quit" onclick="return confirm('Are you sure you want to logout for this session?');"
                                                           value="Save and Quit"/>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
        <tr>
            <td>
                <table>
                    <tr>
                        <td width="10%"></td>
                        <td style="vertical-align:top" width="80%">
                            <h3>Overview</h3>
                            <p>We are correcting two kinds of errors: <em>chunking</em> and <em>coreference</em>.
                                Bracketed partitions of the captions above (ex. [an example]) are <em>chunks</em>. A
                                chunking error occurs when this partitioning is incorrect (an extraneous word
                                appears inside the brackets or a necessary word appears outside]. <em>Coreference chains</em>
                                are groups of NP chunks that refer to the same visual entity or set of entities.
                                Coreference errors occur when a chunk that belongs in a chain is not assigned to it,
                                or when a chunk that does not belong in a chain is assigned to it.
                            </p>
                            The annotation process includes the following steps.
                            <ol>
                                <li>Ensure that each caption describes the image shown. If it doesn't, check the
                                    <em>irrelevant caption</em> checkbox</li>
                                <li><strong>Correct Chunking Errors</strong><br/>
                                    <ol>
                                        <li>
                                            Review the chunks that appear in Coreference Mode, checking for
                                            missing or extraneous words.
                                        </li>
                                        <li>
                                            If there are chunking errors in these chunks - or if there is text
                                            describing visual entities that appears unbrackets in Coreference Mode -
                                            toggle <em>Chunking Mode</em>.<br/>
                                            Ensure all chunk boundaries are correct, and all chunk types are correct.
                                        </li>
                                        <li>
                                            Click the <em>Chunking is correct</em> checkbox
                                        </li>
                                    </ol>
                                </li>
                                <li><strong>Correct Coreference Errors</strong><br/>
                                    <ul>
                                        <li>
                                            Ensure that all coreference chains contain only those chunks that
                                            describe the same entity or set of entities
                                        </li>
                                        <li>
                                            Reassign chunks to coreference chains that are assigned elsewhere or are
                                            unassigned
                                        </li>
                                        <li>
                                            If new chains have been added, select the appropriate boxes in the
                                            <em>Chain / Bounding Box Assignment</em> table to associate the
                                            new chain with the visual entit(ies) to which it refers
                                        </li>
                                        <li>
                                            Click the <em>Coreference is correct</em> checkbox
                                        </li>
                                    </ul>
                                </li>
                                <li>If the image or captions have been especially complex or difficult to
                                    correct, please click the <em>Image needs additional review</em> checkbox and
                                    leave comments explaining the need for the additional review in the
                                    <em>Additional comments</em> field.</li>
                                <li>Click on the <em>Save and Continue</em> button to proceed to the next image</li>
                            </ol>
                        </td>
                        <td width="10%"></td>
                    </tr>
                </table>
            </td>
        <tr>
            <td>
                <table>
                    <tr>
                        <th width=33%>Interface</th>
                        <th width=33%>Chunk Types</th>
                        <th width=33%>Special Notes</th>
                    </tr>
                    <td style="vertical-align: top">
                        <p><strong>Tokens</strong>: Each space-separated part of each caption is
                            a token. Clicking on a token changes the chunk with which it's associated.
                            This allows you to remove a token's chunk assignment, assign a token to a
                            new chunk, or assign a token to the chunks to the immediate left and right.<br/>
                            This is the main method by which chunking errors will be corrected.
                        </p>
                        <p><strong>Chunks</strong>: In <em>Chunking Mode</em>, each bracketed, bolded partition
                            of a caption is a chunk. Chunks have their type shown as a superscript
                            (ex. [<sup>type</sup> chunk]). <br/>
                            Clicking the type superscript changes the type of the chunk.
                        </p>
                        <p><strong>NP Chunks</strong>: In <em>Coreference Mode</em>, each bracketed, bolded
                            partition of a caption shown is an NP chunk. These chunks have a corresponding
                            chain ID shown as a subscript (ex. [chunk <sub>chain</sub>]).<br/>
                            Clicking the chain subscript changes the chain assignment of the chunk. This is
                            the main method by which coreference errors will be corrected.
                        </p>
                        <p><strong>Bounding Boxes</strong>: Bounding boxes are regions of the image corresponding to
                            visual entities. They can be toggled all at once with the <em>Toggle Boxes</em> button.
                            The associations between bounding boxes and the coreference chains to which they refer
                            are shown in the <em>Chain / Bounding Box Assignments</em> table. For pre-existing chains,
                            these assignments are fixed and the checkboxes disabled. For new chains, however,
                            checkboxes must be clicked to associate individual boxes with that chian.<br/>
                            Each cell in the table (<em>i, j</em>) refers to the association between the
                            bounding box for row <em>i</em> and the chain for row <em>j</em>. Clicking on the
                            heading for row <em>i</em> will toggle the visibility for bounding box <em>i</em>.
                            Clicking the heading for column <em>j</em> will toggle the visibility for all
                            bounding boxes associated with chain <em>j</em>.<br/>
                            Column headers marked with asterisks refer to chains that are not contained in
                            the original Flickr30k Entities annotations. These chains have been introduced
                            via chunking correction or systematic changes to the data, and as such should be
                            reviewed with additional scrutiny.
                        </p>
                        <p><strong>Captions</strong>: Each image has five captions. To the right of each of these
                            captions are two checkboxes, one indicating that the caption does not describe the image
                            (irrelevant) and one indicating that the caption contains a typo.
                        </p>
                        <p><strong>Coreference Mode</strong>: Enabled by default, Coreference Mode displays only
                            NP chunks, and enables the chain assignments of those chunks (shown as a subscript) to be
                            changed. The current mode can be toggled with the <em>Toggle Chunk / Coref Mode</em> button.
                        </p>
                        <p><strong>Chunk Mode</strong>: Chunk Mode displays all chunks, enables chunk types to be
                            changed, and allows chunk boundaries to be changed by clicking on tokens. The current mode
                            can be toggled with the <em>Toggle Chunk / Coref Mode</em> button.
                        </p>
                        <p><strong>Review Image</strong>: Below the captions, the review image section contains two
                            buttons. The <em>Reset Image</em> button discards all work and resets the image annotation.
                            The <em>Save and Continue</em> button saves your annotations and retrieves a new image.<br/>
                            The <em>Save and Continue</em> button is only enabled under the following conditions.
                        <ul>
                            <li>The <em>Chunking is correct</em> and <em>Coreference is correct</em>
                                checkboxes have been clicked, indicating that there are no remaining chunking
                                or coreference errors</li>
                            <li>The <em>Image needs additional review</em> checkbox is clicked, indicating
                                that the captions' annotations were too complex to correct</li>
                        </ul>
                        The Review Image section also contains a comments field which should contain comments
                        whenever the <em>Image needs additional review</em> checkbox is clicked.
                        </p>
                    </td>
                    <td valign="top">
                        <ul>
                            <li>
                                <strong>NP</strong>: Noun phrases are chunked minimally, including their determiner
                                and adjectives, as in "[a child] and [his dog] run along [a sandy beach]."
                                <ul>
                                    <li><em>Posessives</em> should be chunked before the appostrophe, which should
                                        be included in the succeeding chunk, as in "[a child] ['s dog] runs."</li>
                                    <li><em>Adjective phrase</em> constituents inside the NP should be included
                                        as part of the NP, as in "[the most interesting dog] runs."</li>
                                    <li><em>Prenominal noun phrases</em> should be assimilated into the NP chunk,
                                        as in "[The Granny Smith apple] is [green]."</li>
                                    <li><em>Proper nouns</em> should be chunked as a single NP, even where the
                                        phrase would otherwise consist of multiple parts, as in
                                        "[The building] features [a sign] that says [Rage Against The Machine]."</li>
                                </ul>
                            </li>
                            <li>
                                <strong>VP</strong>: Verb phrases are chunked to include all VP constituents that
                                would otherwise be embedded, as in "The cat [may not want to pounce] but [will]."
                                <ul>
                                    <li><em>Subsequent VPs</em> may occur when the VPs would not have been
                                        embedded, as in "The mouse the cat [had caught] [is wriggling]."</li>
                                    <li><em>Adverbial phrases</em> that appear before the main verb should be
                                        included as part of the VP, as in "The cat [could very well pounce] at any
                                        moment"</li>
                                    <li><em>Predicative adjectives</em> should not be included in the VP and should
                                        instead appear as an ADJP, as in "The cat [<sup>VP</sup> is]
                                        [<sup>ADJP</sup> annoyed]"</li>
                                    <li><em>Auxiliary verbs</em> in inverted sentences do not belong to any VP
                                        chunk, as "does" in "Not only does the cat [nap], but it also [dozes]."</li>
                                </ul>
                            </li>
                            <li>
                                <strong>ADJP / ADVP</strong>: Adjectival and adverbial phrases are chunked mostly
                                as adjective and adverb constituents
                                <ul>
                                    <li><em>Adverbial phrases</em> inside an ADJP or in front of the main verb of
                                        a VP are assimilated into that ADJP or VP, respectively.</li>
                                    <li><em>Noun phrases</em> inside an ADJP or ADVP are split into two chunks, as
                                        in "He is [<sup>NP</sup> 68 years] [<sup>ADJP</sup> old]" and
                                        "[<sup>NP</sup> A year] [<sup>ADVP</sup> earlier] there was rain."</li>
                                    <li><em>Adjectival phrases</em> inside an NP are assimilated into the NP</li>
                                </ul>
                            </li>
                            <li>
                                <strong>PP</strong>: Perpositional phrase chunks are most often the single
                                word preposition, which does not include any contained NPs, as in
                                "The bird [in] the blue bandana."
                                <ul>
                                    <li><em>Multi-word</em> prepositional phrases are still possible,
                                        as in "Birds [such as] pigeons can carry disease."</li>
                                </ul>
                            </li>
                            <li>
                                <strong>SBAR</strong>: Subordinate clause chunks refer to the portion of the
                                sentence (usually one word otherwise tagged as IN) that indicates the presence
                                of a subordinate clause, as in "The boy reads [while] the girl runs."
                                <ul>
                                    <li><em>Multi-word</em> subordinate clause chunks are still possible,
                                        as in "The boy ran at the ball, [even though] the girl always pulls it away."</li>
                                </ul>
                            </li>
                            <li>
                                <strong>CONJP</strong>: Conjunctions with more than one word are chunked together,
                                as "Cookies [as well as] cakes are delicious." Single word conjunctions, like
                                "and" and "or" are not contained in CONJP chunks.
                            </li>
                            <li>
                                <strong>PRT</strong>: Verb particles are chunked as single words, as in
                                "The scuba diver shows [off] something." The only multi-word particle is
                                "on and off" as in "He's turning the lights [on and off]."
                            </li>
                            <li>
                                <strong>INTJ</strong>: Interjection chunks are often single words, as in
                                "no", "oh", "alas". Multi-word interjections are also possible, as in
                                "good grief".
                            </li>
                        </ul>
                    </td>
                    <td style="vertical-align: top">
                        <p><strong>Nonvisual NP chunks</strong> occur in those cases where an NP chunk refers to
                            an entity that either cannot be pictured or are events, rather than objects (both
                            "time" and "a trick" would be considered nonvisual). In <em>Coreference Mode</em>, some
                            NP chunks may be nonvisual. Nonvisual NP chunks should not be assigned to a chain, which
                            appears as an emdash subscript (ex. [nonvisual <sub>&mdash;</sub>]). However, it is not
                            necessary to remove coreference information for all nonvisual NP chunks. Instead, a good
                            rule of thumb is to make the fewest changes to make the data consistent. If all but one
                            nonvisual chunks are partitioned together in a chain, associate the last nonvisual chunk
                            with the chain. Similarly. if many nonvisual chunks are marked as nonvisual, but one is
                            associated with a chain, remove that association. When in doubt, and when creating new
                            chunks, the correct annotation behavior is that nonvisual chunks should not be associated
                            with a chain.
                        </p>
                        <p><strong>X of Y</strong> constructions, such as "a group of people" are unique cases. In
                            <em>Chunking Mode</em>, these appear as "[<sup>NP</sup> a group] [<sup>PP</sup> of]
                            [<sup>NP</sup> people]". In <em>Coreference Mode</em>, however, many of these
                            constructions appear as "[a group of people <sub>ID</sub>]". This reflects our belief
                            in the construction's need to be represented as an atomic unit when making
                            coreference judgments.
                        <ul>
                            <li>In <em>Chunking Mode</em>, one should be mindful when altering the boundaries of
                                these chunks. In order to maintain the construction's internal representation,
                                the original chunks must be preserved (so new works must be added to / removed from
                                these original chunks). In cases where the original chunk has been lost, reset the
                                image.
                            </li>
                            <li>In <em>Coreference Mode</em>, when the construction appears together, coreference
                                judgments should be made as if the construction is a single unit. When the
                                construction appears as "[X] of [Y]", coreference judgments should be made for X and
                                Y separately. Keep in mind that in many of these cases, Y has automatically been
                                assigned to an empty chain, and these must be manually attached to a preexisting
                                chain, if possible.<br/>
                                For example, "[The middle <sub>a</sub>] of [the road <sub>b</sub>]" should be
                                assigned to chains a and b, respectively, referring to the road and the
                                somewhat amorphous concept of the middle thereof. "[The side] of [a building]"
                                should be annotated similarly, where the side is only a part of the building.
                                Keep in mind that the boxes for a and b in both example are likely the same.
                            </li>
                        </ul>
                        </p>
                        <p><strong>Colocated Entities</strong> refer to those entities that appear in exactly
                            the same location in the image, and are arguably synonymous in the denotation implied
                            by the image. In the general case, these entities should be coreferent, as in
                            "a street corner" and "the side of the road" where the image only shows the street corner.
                            Notable exceptions are below
                        <ul>
                            <li><strong>People in costumes</strong>: In cases where the captions describe
                                a costume, the person wearing it, and the character being portrayed, each
                                of these are their own chain. Keep in mind that the man and the costume chains
                                should refer to the same bounding box.</li>
                            <li><strong>Coverings</strong>: In cases where an entity like a mural
                                is shown and no other part of the wall can be seen, these are still
                                separate chains. Similarly, if a caption describes "[the surface] of
                                [a wall] that is covered in [moss]", the surface, the wall, and
                                the moss are separate entities.</li>
                        </ul>
                        </p>
                        <p><strong>Meronymy</strong> refers to part-whole relationships, which is a common source of
                            coreference errors in our data. In these cases, one chunk will describe multiple entities
                            ("three men") while another chunk describes a subset ("a man"). While in this example
                            "a man" is one of the "three men", these chunks are <em>not</em> coreferent. Coreference
                            relationships exist only when two chunks refer to the <em>exact same</em> entity or set of
                            entities.
                        </p>
                        <p><strong>Parentheses</strong> should generally be excluded from any chunk, even
                            where the parentheses fully enclose a single chunk.</p>
                        <p><strong>Quotes</strong> should only be included in a chunk where they enclose
                            a single NP chunk, as in "A sign reading ['Pets']". In cases where the quotes enclose
                            multiple chunks, the quotes should not be included in any chunk, as in
                            "A sign reading '[<sup>NP</sup> Pets] [<sup>PP</sup> for] [<sup>NP</sup> sale]'."
                        </p>
                    </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</form>
<?php global $imgID; echo $imgID;?>
</body>
</html>