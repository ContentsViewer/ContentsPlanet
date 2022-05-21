<?php
require_once dirname(__FILE__) . "/../ContentsPlanet.php";
require_once dirname(__FILE__) . "/../Module/Authenticator.php";
require_once dirname(__FILE__) . "/../Module/ErrorHandling.php";
require_once dirname(__FILE__) . '/../Module/ServiceUtils.php';
require_once dirname(__FILE__) . "/../Module/ContentDatabaseControls.php";
require_once dirname(__FILE__) . "/../Module/Debug.php";
require_once dirname(__FILE__) . "/../Module/Utils.php";
require_once dirname(__FILE__) . "/../Module/Localization.php";
require_once dirname(__FILE__) . "/../Module/ContentsViewerUtils.php";
require_once(MODULE_DIR . "/PluginLoader.php");

set_error_handler('ErrorHandling\PlainErrorHandler');

use ContentDatabaseControls as DBControls;

ServiceUtils\RequireLoginedSession();
ServiceUtils\RequirePostMethod();
ServiceUtils\ValidateCsrfToken();
ServiceUtils\RequireParams('cmd');
$cmd = $_POST['cmd'];

$username = Authenticator::GetLoginedUsername();
Authenticator::GetUserInfo($username, 'contentsFolder', $contentsFolder);

if ($cmd === 'SaveContent') {
  ServiceUtils\RequireParams('openTime', 'path', 'contentRawText');
  $openTime = $_POST['openTime'];
  $path = $_POST['path'];
  $rawText = $_POST['contentRawText'];

  if (!Authenticator::IsFileOwner($path, $username)) {
    ServiceUtils\SendErrorResponseAndExit('Permission denied.');
  }

  $rawText = str_replace("\r", "", $rawText);
  $updatedTime = 0;

  $realPath = ContentPathUtils::RealPath($path . Content::EXTENSION, false);
  if (file_exists($realPath)) {
    $updatedTime = filemtime($realPath);
  }

  if ($openTime > $updatedTime) {
    file_put_contents($realPath, $rawText, LOCK_EX);
    header('Location: ' . ROOT_URI . Path2URI($path));
    exit;
  }

  RenderDiffEdit($path, file_get_contents($realPath), $rawText);
  exit;
}

ServiceUtils\SendErrorResponseAndExit('Unrecognized command.');


function RenderDiffEdit($path, $oldRawText, $newRawText)
{
  $layerName = DBControls\GetRelatedLayerName($path);
  if ($layerName === false) {
    $layerName = DEFAULT_LAYER_NAME;
  }
  $language = $layerName;
  if (!Localization\SetLocale($language)) {
    $language = 'en';
    Localization\SetLocale($language);
  }

  $contentFileName = basename($path);

?>
<!DOCTYPE html>
<html lang="<?= $language ?>">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <?= PluginLoader::getCommonHead() ?>

  <title>
    <?= Localization\Localize('content-database-edit-service.resolveConflicts', 'Resolve conflicts') ?> | <?= $contentFileName ?>
  </title>

  <script type="text/javascript" src="<?= CLIENT_URI ?>/ThemeChanger/ThemeChanger.js"></script>

  <link rel="stylesheet" href="<?= CLIENT_URI ?>/Common/css/base.css">
  <style type="text/css">
    html {
      height: 100%;
    }

    body {
      height: 100%;
      overflow: hidden;
      margin: 0;
      padding: 0;
    }

    .site-wrapper {
      height: 100%;
      display: flex;
      flex-direction: column;
    }

    .site-wrapper>main {
      position: relative;
      flex-grow: 1;
    }

    .site-wrapper>footer {
      display: flex;
      justify-content: flex-end;
      padding: 0.25rem 0.5rem;
      border-top: 1px solid #dee2e6;
    }

    #diff {
      position: absolute;
      border-top: 0.25rem solid transparent;
      bottom: 0;
      top: 0;
      left: 0;
      right: 0;
    }

    .btn {
      cursor: pointer;
      align-items: center;
      border: 1px solid transparent;
      padding: .375rem .75rem;
      font-size: 1rem;
      border-radius: .25rem;
      transition: color .15s ease-in-out, background-color .15s ease-in-out, border-color .15s ease-in-out, box-shadow .15s ease-in-out;
    }

    .btn-save {
      color: #fff;
      width: 100px;
      background-color: #198754;
      border-color: #198754;
    }

    .btn-save:hover {
      color: #fff;
      background-color: #157347;
      border-color: #146c43;
    }
  </style>

  <script src="<?= CLIENT_URI ?>/node_modules/ace-builds/src-min/ace.js" type="text/javascript" charset="utf-8"></script>
  <script src="<?= CLIENT_URI ?>/node_modules/ace-diff/dist/ace-diff.min.js"></script>
  <link href="<?= CLIENT_URI ?>/node_modules/ace-diff/dist/ace-diff.min.css" rel="stylesheet" id="diff-style-light">
  <link href="<?= CLIENT_URI ?>/node_modules/ace-diff/dist/ace-diff-dark.min.css" rel="stylesheet" id="diff-style-dark" disabled>

  <meta name="token" content="<?= H(Authenticator::GenerateCsrfToken()) ?>" />
  <meta name="content-path" content="<?= $path ?>" />
  <meta name="open-time" content="<?= time() ?>" />
</head>

<body>
  <div class="site-wrapper">
    <main>
      <div id='diff'></div>
    </main>
    <footer>
      <button id='save-button' class="btn btn-save">SAVE</button>
    </footer>
  </div>

  <input type='hidden' id='oldContent' value='<?= H($oldRawText, ENT_QUOTES) ?>'>
  <input type='hidden' id='newContent' value='<?= H($newRawText, ENT_QUOTES) ?>'>

  <script>
    alert(
      "<?= Localization\Localize(
          'content-database-edit-service.resolveMessage',
          'This file has been modified while editing. Please check the difference and save again.'
        ) ?>"
    );

    var token = document.getElementsByName("token").item(0).content;
    var contentPath = document.getElementsByName("content-path").item(0).content;

    var diffStyleLight = document.getElementById("diff-style-light");
    var diffStyleDark = document.getElementById("diff-style-dark");

    var oldContent = document.getElementById('oldContent').value;
    var newContent = document.getElementById('newContent').value;

    var differ = new AceDiff({
      element: '#diff',
      left: {
        content: oldContent,
        editable: false
      },
      right: {
        content: newContent,
        editable: true,
        copyLinkEnabled: false,
      },
    });

    if (ThemeChanger) {
      onChangeTheme();
      ThemeChanger.onChangeThemeCallbacks.push(onChangeTheme);
    }

    function onChangeTheme() {
      if (ThemeChanger.getCurrentTheme() == "dark") {
        differ.getEditors().left.setTheme("ace/theme/twilight");
        differ.getEditors().right.setTheme("ace/theme/twilight");
        diffStyleLight.disabled = true;
        diffStyleDark.disabled = false;
      } else {
        differ.getEditors().left.setTheme("ace/theme/textmate");
        differ.getEditors().right.setTheme("ace/theme/textmate");
        diffStyleLight.disabled = false;
        diffStyleDark.disabled = true;
      }
    }

    document.getElementById('save-button').addEventListener('click', () => SaveContent())

    window.addEventListener('keydown', function(event) {
      if (event.ctrlKey && event.keyCode == 83) {
        event.preventDefault()
        SaveContent()
      }
    })

    var handleBeforeUnload = function(event) {
      // イベントをキャンセルする
      event.preventDefault();
      // Chrome では returnValue を設定する必要がある
      event.returnValue = '';
    }

    window.addEventListener('beforeunload', handleBeforeUnload);

    function SaveContent() {
      if (!window.confirm('Save the content.')) {
        return;
      }
      window.removeEventListener('beforeunload', handleBeforeUnload)

      let openTime = document.getElementsByName("open-time").item(0).content;

      let form = document.createElement('form');
      form.setAttribute('action', 'content-database-edit-service.php');
      form.setAttribute('method', 'POST'); // POSTリクエストもしくはGETリクエストを書く。
      form.style.display = 'none'; // 画面に表示しないことを指定する
      document.body.appendChild(form);

      data = {
        "cmd": "SaveContent",
        "token": token,
        "path": contentPath,
        "openTime": openTime,
        "contentRawText": differ.getEditors().right.session.getValue()
      };

      if (data !== undefined) {
        Object.keys(data).map((key) => {
          let input = document.createElement('input');
          input.setAttribute('type', 'hidden');
          input.setAttribute('name', key);
          input.setAttribute('value', data[key]);
          form.appendChild(input);
        })
      }
      form.submit();
      return;
    }
  </script>
</body>
</html>
<?php
}
