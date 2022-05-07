<?php

require_once(MODULE_DIR . '/Authenticator.php');

Authenticator::RequireLoginedSession($_SERVER["REQUEST_URI"]);

header('Content-Type: text/html; charset=UTF-8');

require_once(MODULE_DIR . '/ContentDatabaseContext.php');
require_once(MODULE_DIR . '/Utils.php');
require_once(MODULE_DIR . '/PathUtils.php');
require_once(MODULE_DIR . "/PluginLoader.php");


$contentsFolder = PathUtils\canonicalize($vars['contentsFolder']);
$contentPath = PathUtils\canonicalize($vars['contentPath']);
$filePath = $contentPath . Content::EXTENTION;
$username = Authenticator::GetLoginedUsername();

if (!Authenticator::IsFileOwner($filePath, $username)) {
  // ファイル所有者が違うため再ログインを要求
  require(FRONTEND_DIR . '/403.php');
  exit();
}

// content情報の用意
$content = new Content();
if (!$content->SetContent($contentPath)) {
  require(FRONTEND_DIR . '/404.php');
  exit();
}


Authenticator::GetUserInfo($username, 'enableRemoteEdit',  $enableRemoteEdit);
Authenticator::GetUserInfo($username, 'remoteURL',  $remoteURL);

if ($enableRemoteEdit) {
  $targetPath = substr($filePath, strlen($contentsFolder));

  $remoteURL = str_replace('{TARGET_PATH}', $targetPath, $remoteURL);

  header("location: $remoteURL");
  exit();
}


$dbContext = new ContentDatabaseContext($contentPath);
$dbContext->LoadMetadata();

$tag2path = $dbContext->database->metadata['tag2path'] ?? [];
ksort($tag2path);

$rawText = $content->rawText;
if (empty($rawText)) {
  $createdAt = date("Y-m-d");
  $title = basename($contentPath);
  $editing = Localization\Localize('editing', 'editing');
  $rawText = <<<EOD
<Header>
    <Parent> 
    <Title> {$title}
    <CreatedAt> {$createdAt}
    <Tags> {$editing}
    <Summary>
        
    </Summary>
</Header>

EOD;
}


?>
<!DOCTYPE html>
<html lang="<?= $vars['language'] ?>">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <?= PluginLoader::getCommonHead() ?>

  <title><?= Localization\Localize('editing', 'Editing') ?> | <?= NotBlankText([$content->title, basename($content->path)]) ?></title>
  <link rel="shortcut icon" href="<?= CLIENT_URI ?>/Common/favicon-editor.ico" type="image/vnd.microsoft.icon" />

  <meta name="token" content="<?= H(Authenticator::GenerateCsrfToken()) ?>" />
  <meta name="content-path" content="<?= $content->path ?>" />
  <meta name="open-time" content="<?= time() ?>" />

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

    .split-view {
      display: flex;
    }

    .split-view.vertical {
      flex-direction: column;
    }

    .split-view.horizontal {
      flex-direction: row;
    }

    .split-view>*:not(.gutter) {
      flex-grow: 1;
      overflow: hidden;
    }

    .split-view>.gutter {
      background-color: #eee;
      background-repeat: no-repeat;
      background-position: 50%;
      flex-shrink: 0;
    }

    .split-view.horizontal>.gutter {
      background-image: url('data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAUAAAAeCAYAAADkftS9AAAAIklEQVQoU2M4c+bMfxAGAgYYmwGrIIiDjrELjpo5aiZeMwF+yNnOs5KSvgAAAABJRU5ErkJggg==');
      cursor: col-resize;
      width: 10px;
    }

    .split-view.vertical>.gutter {
      background-image: url('data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAB4AAAAFAQMAAABo7865AAAABlBMVEVHcEzMzMzyAv2sAAAAAXRSTlMAQObYZgAAABBJREFUeF5jOAMEEAIEEFwAn3kMwcB6I2AAAAAASUVORK5CYII=');
      cursor: row-resize;
      height: 10px;
    }

    .site-wrapper {
      height: 100%;
      display: flex;
      flex-direction: column;
    }

    .site-wrapper>main {
      position: relative;
      flex-grow: 1;
      overflow: hidden;
    }

    .site-wrapper>footer {
      display: flex;
      justify-content: flex-end;
      padding: 0.25rem 0.5rem;
      border-top: 1px solid #dee2e6;
    }

    #editor {
      border-top: 0.25rem solid transparent;
      margin: 0;
    }

    #toolbar {
      overflow-y: auto;
    }

    #preview-iframe {
      height: 100%;
      width: 100%;
    }

    .toolbar {
      display: flex;
      padding: 0.25rem 1rem;
      font-size: 12px;
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

    .toolbar button {
      font-size: 1em;
      height: 20px;
      padding: 0 0.5em;
      border-color: rgb(218, 220, 224);
      background-color: white;
    }

    .toolbar button:hover {
      background-color: rgb(241, 243, 244);
    }

    .toolbar button:active {
      background-color: rgb(232, 240, 254);
    }

    .toolbar select {
      font-size: 1em;
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

    .btn-preview {
      position: absolute;
      right: 0;
      width: 50px;
      height: 50px;
      padding: 0;
      text-align: center;
      font-size: 0.5em;
      opacity: 0.8;
    }
  </style>

  <script type="text/javascript" src="<?= CLIENT_URI ?>/ThemeChanger/ThemeChanger.js"></script>
</head>

<body>
  <div class='site-wrapper'>
    <main id="main" class="split-view vertical">
      <div id='toolbar' style="height: 50px; flex: none;">
        <div class="toolbar">
          <div>
            <?= Localization\Localize('tags', 'Tags') ?>:
            <select id='tag-select'>
              <?php foreach ($tag2path as $tag => $_) : ?>
                <option><?= H($tag) ?></option>
              <?php endforeach; ?>
            </select>
            <button id='tag-insert-button' class='btn'>Insert</button>
          </div>
        </div>
      </div>
      <div class="gutter"></div>
      <div class="split-view horizontal">
        <pre id='editor' style="width: 55%; flex: none;"><?= H($rawText) ?></pre>
        <div class="gutter"></div>
        <div id='preview'>
          <button id='preview-button' class='btn btn-preview'>Preview</button>
          <iframe id='preview-iframe' name='preview-iframe'></iframe>
        </div>
      </div>
    </main>
    <footer>
      <button id='save-button' class="btn btn-save">SAVE</button>
    </footer>
  </div>

  <form name="previewForm" method="post" enctype="multipart/form-data" action="?cmd=preview" target="preview-iframe">
    <input type="hidden" name="rawText" value="">
    <input type="hidden" name="token" value="<?= H(Authenticator::GenerateCsrfToken()) ?>">
  </form>

  <script src="<?= CLIENT_URI ?>/SplitView/SplitView.js" type="text/javascript" charset="utf-8"></script>
  <script src="<?= CLIENT_URI ?>/node_modules/ace-builds/src-min/ace.js" type="text/javascript" charset="utf-8"></script>

  <script>
    const token = document.getElementsByName("token").item(0).content;
    const contentPath = document.getElementsByName("content-path").item(0).content;

    // At first, we need to freeze layout before the editor change it.
    SplitView.activate(document.getElementById("main"))

    const editor = ace.edit("editor")
    editor.setTheme("ace/theme/twilight");
    editor.getSession().setMode("ace/mode/markdown");
    editor.session.setTabSize(4);
    editor.session.setUseSoftTabs(true);
    editor.session.setUseWrapMode(false);

    document.getElementById('tag-insert-button').addEventListener('click', () => {
      const tag = document.getElementById('tag-select').value
      editor.insert(tag)
    })

    document.getElementById('preview-button').addEventListener('click', () => previewContent())

    document.getElementById('save-button').addEventListener('click', () => saveContent())

    window.addEventListener('keydown', function(event) {
      if (event.ctrlKey && event.keyCode == 83) {
        event.preventDefault()
        saveContent()
      }
    })

    var handleBeforeUnload = function(event) {
      // イベントをキャンセルする
      event.preventDefault();
      // Chrome では returnValue を設定する必要がある
      event.returnValue = '';
    }

    const resizeObserver = new ResizeObserver(entries => {
      editor.resize()
    })
    resizeObserver.observe(document.getElementById('editor'))

    window.addEventListener('beforeunload', handleBeforeUnload);

    previewContent()

    function previewContent() {
      const rawText = editor.session.getValue();
      const form = document.forms.previewForm
      form.elements.rawText.value = rawText
      form.submit()
    }

    function saveContent() {
      // まず, フォーカスされている要素のフォーカスを外す.
      document.activeElement.blur();

      if (!window.confirm('Save the content.')) {
        return;
      }
      window.removeEventListener('beforeunload', handleBeforeUnload)

      let openTime = document.getElementsByName("open-time").item(0).content;

      let form = document.createElement('form');
      form.setAttribute('action', '<?= SERVICE_URI ?>/content-database-edit-service.php');
      form.setAttribute('method', 'POST');
      form.style.display = 'none'; // 画面に表示しないことを指定する
      document.body.appendChild(form);

      let data = {
        "cmd": "SaveContent",
        "token": token,
        "path": contentPath,
        "openTime": openTime,
        "contentRawText": editor.session.getValue()
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