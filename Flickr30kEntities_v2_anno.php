<?php
session_start();

//init our global vars
$servername = $_SESSION['servername'];
$imgWebRoot = $_SESSION['imgWebRoot'];
$userTier = $_SESSION['tier'];
$user = $_SESSION['usr'];

//create a DB conn
$conn = new mysqli($servername,$_SESSION['username'],
    $_SESSION['password'],$_SESSION['database']);
if($conn->connect_error){
    die("Connection failed: ".$conn->connect_error);
}


//if we got here via the next button, update the DB
//with the last page's info
if(isset($_POST['btn:sub_next'])) {
    updateTables();
} else if (isset($_POST['btn:sub_quit'])) {
    updateTables();
    header('location: imgCleanup_logout.php');
}


$imgID = getNextImg();

//get the image dimensions and previously reviewed states
$query_img = "SELECT width, height ".
             "FROM img ".
             "WHERE img_id='".$imgID."';";
$result_img = $conn->query($query_img);
$width=-1;
$height=-1;
if($result_img->num_rows > 0)
{
    while($row = $result_img->fetch_assoc()) {
        $width = $row["width"];
        $height = $row["height"];
    }
}


//dont forget to close the conn
$conn->close();

function getNextImg()
{
    global $conn, $user;
    $imgID = "";
    $numIter = 0;

    //There may be a more principled, sql way for this,
    //but what we want is a random image that has a irrelevant_reviewed
    //caption that has also not been reviewed by the user
    while($imgID == "" && $numIter < 50){
        //get a random img
        $query_img = "SELECT DISTINCT img_id ".
                     "FROM caption ".
                     "WHERE reviewed_irrelevant=1 ".
                     "AND reviewed=0 ".
                     "ORDER BY RAND() LIMIT 1";
        $result_img = $conn->query($query_img);
        if($result_img->num_rows > 0) {
            while($row = $result_img->fetch_assoc()) {
                $imgID = $row["img_id"];
            }
        }

        //get the annotators for this img
        $query_reviewers = "SELECT DISTINCT reviewer_id ".
                           "FROM token_anno ".
                           "WHERE img_id='".$imgID."';";
        $reviewerArr = array();
        $result_reviewers = $conn->query($query_reviewers);
        if($result_reviewers->num_rows >0){
            while($row = $result_reviewers->fetch_assoc()){
                array_push($reviewerArr, $row["reviewer_id"]);
            }
        }
        $query_origReviewers = "SELECT reviewer_id ".
                               "FROM img ".
                               "WHERE img_id='".$imgID."';";
        $result_reviewers = $conn->query($query_origReviewers);
        if($result_reviewers->num_rows >0){
            while($row = $result_reviewers->fetch_assoc()){
                array_push($reviewerArr, $row["reviewer_id"]);
            }
        }

        //if this user is one of the annotators, we need a different image
        if(in_array($user, $reviewerArr)){
            $imgID = "";
        }
        $numIter++;
    }
    return $imgID;
}

/**Updates the tables with the previous page's changes,
 * all contained in the POST object
 */
function updateTables()
{
    global $conn, $user;

    //get the then-current image id from the hidden tag
    $prevImgID = $_POST['hdn:currentImg'];

    //get the previous duration
    $prevDuration = $_POST['hdn:duration'];

    //get the caption text
    $capText = $_POST["txt:caption"];

    //get the current max caption idx
    $query_capIdx = "SELECT caption_idx ".
                    "FROM caption ".
                    "WHERE img_id='".$prevImgID."'".
                    "ORDER BY caption_idx DESC LIMIT 1;";
    $result_capIdx = $conn->query($query_capIdx);
    $capIdx = 4;
    if($result_capIdx->num_rows > 0) {
        while($row = $result_capIdx->fetch_assoc()) {
            $capIdx = intval($row["caption_idx"]);
        }
    }
    $capIdx++;

    $query_cap = "INSERT INTO caption(img_id, caption_idx, caption, reviewer_id) ".
                 "VALUES ('".$prevImgID."','".$capIdx."','" . $capText . "','".
                 $user . "');";
    if($conn->query($query_cap) === FALSE) {
        die('Failed to update DB with <br/>'.$query_cap);
    }

    $query_oldCap = "UPDATE caption SET reviewed=1 WHERE img_id='".$prevImgID."';";
    if($conn->query($query_oldCap) === FALSE) {
        die('Failed to update DB with <br/>'.$query_oldCap);
    }

    //update our session vars
    $_SESSION['reviewedImgs'] += 1;
    $_SESSION['totalTime'] += $prevDuration;
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

?>


<html>
<head>
    <link rel="stylesheet" type="text/css" href="imgCleanup_style.css">
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/font-awesome/4.4.0/css/font-awesome.min.css">
</head>
<body onload="init()">
<script type="text/javascript">
    var startTS;

    function init()
    {
        //draw boxes
        drawBoxes();

        //get the start TS
        startTS = Math.floor(Date.now() / 1000);
    }

    /**Draws the image and bounding boxes, using html5 canvas
     */
    function drawBoxes()
    {
        //get the canvas element and a 2d context
        var canvas = document.getElementById("canvas");
        var context = canvas.getContext("2d");

        //draw the image
        var img = new Image();
        img.src = "<?php echo getImgSrc();?>";
        context.drawImage(img, 0, 0);
    }

    function setEndTimeStamp()
    {
        document.getElementById("hdn:duration").value =
            Math.floor(Date.now() / 1000) - startTS;
    }

    function confirmExit(isQuitting)
    {
        var text = document.getElementById("txt:caption").value;
        if(text.length >= 25)
        {
            if(!isQuitting ||
               isQuitting && confirm('Are you sure you want to logout for this session?')){

                document.getElementById("txt:caption").value = text.replace(/'/g, "\\'").replace(/;/g, "\\;");
                setEndTimeStamp();
                return true;
            }
        } else {
            alert("Captions must be at least 25 characters long.");
        }
        return false;
    }
</script>
<form method="post" action="">
    <input type="hidden" name="hdn:currentImg" value="<?php global $imgID; echo $imgID;?>"/>
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
            <td>
                <table width="100%">
                    <tr>
                        <td>
                            <h4>Instructions</h4>
                            Please enter a description of this image. Be sure to...
                            <ol>
                                <li>describe the people, actions, and (if appropriate) scene of the image</li>
                                <li>be mindful of spelling and grammar</li>
                                <li>include a verb</li>
                            </ol>
                            Please do not speculate, or be vague, humorous, or write nonsense.
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <label for="txt:caption" style="font-weight:bold;">Caption</label>
                            <textarea rows="6" name="txt:caption" id="txt:caption"
                            placeholder="Enter your caption here"></textarea>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
        <tr>
            <td></td>
            <td>
                <table cellpadding="10px">
                    <tr>
                        <td>
                            <input type="submit" onclick="return confirmExit(false)" id="btn:sub_next"
                                   name="btn:sub_next" title="Both chunking and coref must be correct to enable"
                                   value="Save and Continue"/>
                        </td>
                        <td>
                            <input type="submit" onclick="return confirmExit(true)" id="btn:sub_quit"
                                   name="btn:sub_quit" value="Save and Quit"/>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</form>
</body>
</html>
