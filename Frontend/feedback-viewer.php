<?php

require_once(MODULE_DIR . '/Authenticator.php');
Authenticator::RequireLoginedSession($_SERVER["REQUEST_URI"]);

require_once(MODULE_DIR . '/CacheManager.php');
require_once(MODULE_DIR . '/Utils.php');
require_once(MODULE_DIR . '/ContentsViewerUtils.php');

use ContentsViewerUtils as CVUtils;


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

$timeline = 'No feedback.';
if(!empty($feedbacks)) {
  SortFeedbacks($feedbacks);
  $timeline = CreateTimelineElement($feedbacks);
}

?>
<!DOCTYPE html>
<html lang="<?=$vars['language']?>">

<head>
  <?php readfile(CLIENT_DIR . "/Common/CommonHead.html");?>
  
  <title>Feedback Viewer</title>
  <link rel="shortcut icon" href="<?=CLIENT_URI?>/Common/favicon-feedback.ico" type="image/vnd.microsoft.icon" />

  <link type="text/css" rel="stylesheet" href="<?=CLIENT_URI?>/FeedbackViewer/style.css" />
  <script type="text/javascript" src="<?=CLIENT_URI?>/ThemeChanger/ThemeChanger.js"></script>

  <meta name="token" content="<?=H(Authenticator::GenerateCsrfToken())?>" />
</head>

<body>
  <main>
    <h1>Feedback Viewer</h1>
    <?=$timeline?>
  </main>
  
  <div id='loading-box'>
    <span id='remaining'></span>
    <div class='spinner'>
      <div class='cube1'></div>
      <div class='cube2'></div>
    </div>
  </div>

  <script>
    
    function DeleteFeedback(button) {
      var token = document.getElementsByName('token').item(0).content;

      var action = button.parentNode; 
      while(action) {
        if(action.classList.contains('action')) break;
        action = action.parentNode;
      }
      var contentPath = action.getAttribute('data-contentPath');
      var id = action.getAttribute('data-id');

      var form = new FormData();
      form.append("cmd", 'delete');
      form.append("contentPath", contentPath);
      form.append("token", token);
      form.append("id", id);
        
      alert("Delete feedback.")
      if (!window.confirm('Are you sure?')) {
        return;
      }

      var xhr = new XMLHttpRequest();
      xhr.open("POST", "Service/feedback-service.php", true);
      xhr.onload = function (e) {
        OnReceiveResponse();
        try {
          if (!ValidateResponse(this)) {
            throw "Sorry... Internal Error occured.";
          }

          if (this.parsedResponse.error) {
            throw this.parsedResponse.error;
          }
        }
        catch (err) {
          alert(err);
          alert('failed to delete the feedback.');
          return;
        }
        action.parentNode.removeChild(action);
      }
      OnSendRequest();
      xhr.send(form);
    }

    function ValidateResponse(xhr) {
      if (xhr.status != 200) {
        console.error("Lost server.");
        return false;
      }

      xhr.parsedResponse = null;
      try {
        xhr.parsedResponse = JSON.parse(xhr.response);
      }
      catch (error) {
        console.error("Fatal Error in the server.\n" + xhr.response)
        return false;
      }

      return true;
    }
    
    var requestCount = 0;
    function OnSendRequest()     { requestCount++; }
    function OnReceiveResponse() { requestCount--; }

    var timerId = setTimeout(Update, 1000);
    function Update() {
      var loadingBox = document.getElementById('loading-box');
      var remaining = document.getElementById('remaining');

      if (requestCount > 0) {
        loadingBox.style.visibility = '';
        remaining.textContent = requestCount;
        timerId = setTimeout(Update, 1000);
      } else {
        loadingBox.style.visibility = 'hidden';
        timerId = setTimeout(Update, 500);
      }
    }

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
        '<a target="_blank" href ="' . CVUtils\CreateContentHREF($feedback['contentPath']) . '">'.
        $feedback['contentPath'] . '</a>';
      $time = '<div class="time">' . date('H:i:s', $feedback['ts']) . '</div>';
      $deleteButton = '<button class="icon trash-icon delete-button" onclick="DeleteFeedback(this)"></button>';
      $html .= '<div class="action" data-id="' . H($feedback['id']) . '" data-contentPath="' . $feedback['contentPath'] . '">';
      switch($feedback['type']) {
          case 'rating':
              if($feedback['rating'] > 2) {
                  $html .= '<div class="head rating high"></div>';
                  $html .= '<div class="title"><div class="text">Rated higher <br />' . $contentLink . '</div>' . $time . $deleteButton . '</div>';
              }
              else {
                 $html .= '<div class="head rating low"></div>';
                 $html .= '<div class="title"><div class="text">Rated lower <br />' . $contentLink . '</div>' . $time . $deleteButton . '</div>';
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