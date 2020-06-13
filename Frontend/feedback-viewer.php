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
$feedbackMap = $feedbackCache->data['feedbacks'] ?? [];

$feedbacks = [];
foreach($feedbackMap as $contentPath => $each) {
    foreach($each as $id => $feedback) {
        $feedbacks[] = array_merge(
            $feedback,
            [
                'contentPath' => $contentPath,
                'id'          => $id
            ]
        );
    }
}

$timeline = '';
SortFeedbacks($feedbacks);
$timeline .= CreateTimelineElement($feedbacks);

?>
<!DOCTYPE html>
<html lang="<?=$vars['language']?>">

<head>
  <?php readfile(CLIENT_DIR . "/Common/CommonHead.html");?>
  
  <title>Feedback Viewer</title>
  <link rel="shortcut icon" href="<?=CLIENT_URI?>/Common/favicon-feedback.ico" type="image/vnd.microsoft.icon" />

  <link type="text/css" rel="stylesheet" href="<?=CLIENT_URI?>/FeedbackViewer/style.css" />
  <script type="text/javascript" src="<?=CLIENT_URI?>/ThemeChanger/ThemeChanger.js"></script>
  <script type="text/javascript" src="<?=CLIENT_URI?>/FeedbackViewer/app.js"></script>

  <meta name="token" content="<?=H(Authenticator::GenerateCsrfToken())?>" />
</head>

<body>
  <main>
    <h1>Feedback Viewer</h1>
    <?=$timeline?>
  </main>
  
  <script src="<?=CLIENT_URI?>/FeedbackViewer/app.js" type="text/javascript" charset="utf-8"></script>
  <script>
    
  </script>
</body>

</html>

<?php

function CreateTimelineElement($feedbacks) {
  $html = '<div class="timeline">';
  $prevDay = 'NON';
  foreach($feedbacks as $feedback) {
      $day = date('M j Y', $feedback['ts']);
      if($day !== $prevDay) {
          $html .= '<h3 class="day-heading">' . $day . '</h3>';
          $prevDay = $day;
      }
      $contentLink = 
        '<a target="_blank" href ="' . CreateContentHREF($feedback['contentPath']) . '">'.
        $feedback['contentPath'] . '</a>';
      $time = '<div class="time">' . date('H:i:s', $feedback['ts']) . '</div>';
      $deleteButton = '<button class="icon trash-icon delete-button"></button>';
      $html .= '<div class="action">';
      switch($feedback['type']) {
          case 'rating':
              if($feedback['rating'] > 2) {
                  $html .= '<div class="head rating high"></div>';
                  $html .= '<div class="title"><div class="text">Rated higher ' . $contentLink . '</div>' . $time . $deleteButton . '</div>';
              }
              else {
                 $html .= '<div class="head rating low"></div>';
                 $html .= '<div class="title"><div class="text">Rated lower ' . $contentLink . '</div>' . $time . $deleteButton . '</div>';
              }
                
              break;
          case 'message':
              $html .= '<div class="head message"></div>';
              $html .= 
                '<div class="title"><div class="text">' . $contentLink . '</div>' . $time . $deleteButton . '</div>' .
                '<div class="card">' .
                  '<div class="title">Message</div>' .
                  '<div class="content">' . H($feedback['message']) . "</div>" .
                '</div>';
              break;
          default:
              break;
      }
      $html .= '</div>';
  }
  $html .= '</div>';
  return $html;
}

function SortFeedbacks(&$feedbacks) {
    return usort($feedbacks, function($a, $b) {
        return $b['ts'] - $a['ts'];
    });
}