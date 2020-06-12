<?php

require_once(MODULE_DIR . '/Authenticator.php');
Authenticator::RequireLoginedSession();

require_once(MODULE_DIR . '/CacheManager.php');
require_once(MODULE_DIR . '/Utils.php');
require_once(MODULE_DIR . '/ContentsViewerUtils.php');

$username = Authenticator::GetLoginedUsername();
$feedbackCache = new Cache();
$feedbackCache->Connect('feedback-' . $username);
$feedbackCache->Lock(LOCK_SH); $feedbackCache->Fetch(); $feedbackCache->Unlock();
$feedbackCache->Disconnect();
$feedbacks = $feedbackCache->data['feedbacks'] ?? [];


function CreateTimelineElement($feedbacks) {
    $html = '<div class="timeline">';
    $prevDay = 'NON';
    foreach($feedbacks as $id => $feedback) {
        $day = date('M j Y', $feedback['ts']);
        if($day !== $prevDay) {
            $html .= '<h3 class="day-heading">' . $day . '</h3>';
            $prevDay = $day;
        }
        $html .= '<div class="action">';
        switch($feedback['type']) {
            case 'rating':
                if($feedback['rating'] > 2) {
                  $html .= '<div class="head rating high"></div>';
                  $html .= '<div class="title">Rated higher</div>';
                }
                else {
                  $html .= '<div class="head rating low"></div>';
                  $html .= '<div class="title">Rated lower</div>';
                }
                break;
            case 'message':
                $html .= '<div class="head message"></div>';
                $html .= 
                  '<div class="card">' .
                    '<div class="title">Message</div>' .
                    '<div class="content">' . H($feedback['message']) . "</div>" .
                  '</div>';
                break;
            default:
        }
        $html .= '<div class="time">' . date('H:i:s', $feedback['ts']) . '</div>';
        $html .= '</div>';
    }
    $html .= '</div>';
    return $html;
}

$timelines = '';
foreach ($feedbacks as $contentPath => $each) {
    $timelines .= '<h2><a href="' . CreateContentHREF($contentPath) . '" target="_blank">' . $contentPath . "</a></h2>"; 
    $timelines .= CreateTimelineElement(array_reverse($each));
}

?>
<!DOCTYPE html>
<html lang="<?=$vars['language']?>">

<head>
  <?php readfile(CLIENT_DIR . "/Common/CommonHead.html");?>
  
  <title>Feedback Viewer</title>
  <link rel="shortcut icon" href="<?=CLIENT_URI?>/Common/favicon-feedback.ico" type="image/vnd.microsoft.icon" />

  <script type="text/javascript" src="<?=CLIENT_URI?>/ThemeChanger/ThemeChanger.js"></script>
  <script type="text/javascript" src="<?=CLIENT_URI?>/FeedbackViewer/FeedbackViewer.js"></script>
  <link type="text/css" rel="stylesheet" href="<?=CLIENT_URI?>/FeedbackViewer/FeedbackViewer.css" />

</head>

<body>
  <main>
    <h1>Feedback Viewer</h1>
    <?=$timelines?>
  </main>
</body>

</html>