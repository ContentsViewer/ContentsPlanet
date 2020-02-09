<?php

require_once(MODULE_DIR . '/Authenticator.php');

Authenticator::RequireLoginedSession($_SERVER["REQUEST_URI"]);

header('Content-Type: text/html; charset=UTF-8');

require_once(MODULE_DIR . '/ContentsDatabaseManager.php');
require_once(MODULE_DIR . '/Utils.php');


$contentPath = $vars['contentPath'];
$fileName = $contentPath . '.content';
$username = Authenticator::GetLoginedUsername();

// if (!Authenticator::IsFileOwner($fileName, $username)) {
//     // ファイル所有者が違うため再ログインを要求
//     require(FRONTEND_DIR . '/403.php');
//     exit();
// }

// content情報の用意
$content = new Content();
$content->SetContent($contentPath);

ContentsDatabaseManager::LoadRelatedMetadata($contentPath);


Authenticator::GetUserInfo($username, 'enableRemoteEdit',  $enableRemoteEdit);
Authenticator::GetUserInfo($username, 'remoteURL',  $remoteURL);
Authenticator::GetUserInfo($username, 'remoteIncludeSubURL',  $remoteIncludeSubURL);
if($enableRemoteEdit){
    $pos = strpos($fileName, "/Contents/");
    if ($pos === false) {
        $vars['errorMessage'] = 'Contentパスが不正です.';
        require(FRONTEND_DIR . '/400.php');
        exit();
    }

    $remoteURL = str_replace('{CONTENT_PATH}', substr($fileName, $pos + strlen("/Contents/")), $remoteURL);
    header("location: $remoteURL");
    exit();
}


?>


<!DOCTYPE html>
<html lang="ja">

<head>
  <?php readfile(CLIENT_DIR . "/Common/CommonHead.html");?>
  <title>編集 | <?=$content->title;?></title>
  <link rel="shortcut icon" href="<?=CLIENT_URI?>/Common/favicon-editor.ico" type="image/vnd.microsoft.icon" />

  <script type="text/javascript" src="<?=CLIENT_URI?>/ThemeChanger/ThemeChanger.js"></script>

  <style type="text/css">
  body {
    overflow: hidden;
  }

  #head {
    overflow-y: scroll;
    position: absolute;
    top: 0px;
    right: 50%;
    left: 0;
    height: 30%;
    line-height: 0.7em;
  }

  #title-input {
    width: 100%;
    height: 2em;
    font-size: 1.2em;
  }

  #summary-editor {
    margin: 0;
    position: absolute;

    top: 0;
    bottom: 70%;
    right: 0;
    left: 50%;
  }

  #body-editor {
    margin: 0;
    position: absolute;

    top: 30%;
    bottom: 50px;
    left: 0;
    right: 50%;
  }

  #preview-field {
    margin: 0;
    position: absolute;
    width: 50%;
    bottom: 0;
    top: 0;
    left: 50%;
    right: 0;
  }

  #preview {
    height: 100%;
    width: 100%;
  }

  .preview-button {
    text-align: center;
    position: absolute;
    width: 50px;
    height: 50px;
    right: 0;
    font-size: 0.5em;
    border-radius: 5px;
    opacity: 0.8;
    cursor: pointer;
    z-index: 99;
  }

  #logout {
    position: absolute;
    left: 0;
    top: 95%;
    margin: 0;
    /* height: 5%; */
    z-index: 100;
  }

  ul.tag-list {
    list-style: none;
  }

  ul.tag-list li {
    display: inline-block;
    margin: 0 .3em .3em 0;
    padding: 0;
  }

  .remove {
    width: 1em;
    text-align: center;
    cursor: pointer;
    color: red;
    border: solid red;
  }

  .add {
    width: 1em;
    text-align: center;
    cursor: pointer;
    color: green;
    border: solid green;
  }

  .save {
    position: absolute;
    right: 0;
    bottom: 0;
    font: 3em;
    top: 95%;
    width: 100px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    color: green;
    border: solid green;
    z-index: 99;
  }
  </style>
</head>

<body>
  <input type="hidden" id="token" value="<?=H(Authenticator::GenerateCsrfToken())?>">
  <input type="hidden" id="contentPath" value="<?=$content->path?>">
  <input type="hidden" id="openTime" value="<?=time()?>">

  <p id='logout'><a href="<?=ROOT_URI?>/Logout?token=<?=H(Authenticator::GenerateCsrfToken())?>">ログアウト</a></p>

  <div id='head'>
    <div>
      タイトル: <input id='title-input' type='text' value='<?=H($content->title);?>'>
    </div>
    <div>
      作成日: <input id='created-at-input' type='text' value='<?php
      $createdAt = $content->createdTimeRaw;
      if ($createdAt === "") {
        // date_default_timezone_set('Asia/Tokyo');
        $createdAt = date("Y-m-d");
      }
      echo H($createdAt);
      ?>'>
    </div>
    <hr>
    <div>
      タグ:
      <ul class='tag-list' id='tag-list'>
        <?php
        foreach ($content->tags as $tag) {
          echo '<li name="' . H($tag) . '">' . $tag . '<span class="remove" onclick=RemoveTag(event)>x</span></li>';
        }
        ?>
      </ul>

      <select id="new-tag-list">
        <?php
        $tag2path = ContentsDatabase::$metadata['tag2path'];
        ksort($tag2path);
        foreach ($tag2path as $tagName => $pathList) {
          echo "<option>" . H($tagName) . "</option>";
        }
        ?>
      </select>
      <span class='add' onclick=AddTagFromList(event)>+</span>

      <input id="new-tag-input">
      <span class='add' onclick=AddTagFromInput(event)>+</span>
    </div>
    <hr>
    <div>
      親コンテンツ: <input type='text' id='parent-input' value='<?=H($content->parentPath)?>'>
    </div>
    <hr>
    <div>
      子コンテンツ:
      <textarea id='children-input' cols=50 rows=<?=$content->ChildCount() + 2?>><?php
      foreach ($content->childPathList as $child) {
        echo H($child) . "\n";
      }
      ?></textarea>
    </div>
  </div>

  <pre id="summary-editor"><?=H($content->summary);?></pre>

  <pre id="body-editor"><?=H($content->body);?></pre>

  <div class='save' onclick=SaveContentFile()>SAVE</div>
  <div id="preview-field">
    <button class='preview-button' onclick='rerenderFunc();'>Preview</button>
    <iframe id='preview' name='preview'></iframe>
  </div>


  <form name="outlineTextForm" method="post" enctype="multipart/form-data" action="?cmd=preview" target="preview">
    <input type="hidden" name="plainText" id="plainTextToSend" value="">
    <input type="hidden" name="token" value="<?=H(Authenticator::GenerateCsrfToken())?>">
  </form>

  <script src="<?=CLIENT_URI?>/Splitter/Splitter.js" type="text/javascript" charset="utf-8"></script>
  <script src="<?=CLIENT_URI?>/ace/src-min/ace.js" type="text/javascript" charset="utf-8"></script>

  <script>
  token = document.getElementById('token').value;
  contentPath = document.getElementById('contentPath').value;

  var summaryEditor = ace.edit("summary-editor");
  InitEditor(summaryEditor);

  var bodyEditor = ace.edit("body-editor");
  InitEditor(bodyEditor);


  splitter = new Splitter(Splitter.Direction.Horizontal,
    document.getElementById('head'),
    document.getElementById('body-editor'), {
      'percent': 30,
      'rect': new Rect(new Vector2(0, 0), new Vector2(100, 95)),
      'onResizeElementBCallbackFunc': function() {
        bodyEditor.resize();
      }
    });

  splitter.Split(Splitter.Side.A, Splitter.Vertical,
    document.getElementById('summary-editor'), 50,
    function() {
      summaryEditor.resize();
    });

  splitter.Split(Splitter.Side.B, Splitter.Vertical,
    document.getElementById('preview-field'));

  var rerenderFunc = function() {
    var plainText = summaryEditor.session.getValue();
    plainText += "\n\n------\n\n" + bodyEditor.session.getValue();
    plainTextToSend.value = plainText;
    document.outlineTextForm.submit();
  }

  summaryEditor.session.setValue(Unindent(summaryEditor.session.getValue(), 2));

  rerenderFunc();

  document.onkeydown =
    function(e) {
      if (event.ctrlKey) {
        if (event.keyCode == 83) {
          SaveContentFile();
          event.keyCode = 0;
          return false;
        }
      }
    }

  document.onkeypress =
    function(e) {
      if (e != null) {
        if ((e.ctrlKey || e.metaKey) && e.which == 115) {
          SaveContentFile();
          return false;
        }
      }
    }

  window.onbeforeunload = function(event) {
    event = event || window.event;
    event.returnValue = 'ページから移動しますか？';
  }

  function InitEditor(editor) {
    editor.setTheme("ace/theme/monokai");
    editor.getSession().setMode("ace/mode/markdown");
    editor.session.setTabSize(4);
    editor.session.setUseSoftTabs(true);
    editor.session.setUseWrapMode(false);

    editor.session.on('change', function(delta) {
      //alert(timerId);
      // if(timerId != null){
      //     clearTimeout(timerId);
      //     timerId = null;
      // }
      // timerId = setTimeout(rerederFunc, 1000);
    });
  }

  function RemoveTag(event) {
    event.target.parentNode.parentNode.removeChild(event.target.parentNode);
  }

  function AddTagFromList(event) {
    newTagList = document.getElementById('new-tag-list');
    tagList = document.getElementById('tag-list');

    tagList.appendChild(CreateTagElement(newTagList.value));
  }

  function AddTagFromInput(event) {
    newTagInput = document.getElementById('new-tag-input');
    tagList = document.getElementById('tag-list');

    tagList.appendChild(CreateTagElement(newTagInput.value));
  }

  function CreateTagElement(tagName) {
    element = document.createElement('li');

    element.setAttribute('name', tagName);
    element.textContent = tagName;
    span = document.createElement('span');
    span.setAttribute('class', 'remove');
    span.setAttribute('onclick', 'RemoveTag(event)');
    span.textContent = 'x';
    element.appendChild(span);

    return element;
  }

  function SaveContentFile() {
    // まず, フォーカスされている要素のフォーカスを外す.
    document.activeElement.blur();

    content = {
      'path': '',
      'title': '',
      'createdAt': '',
      'parentPath': '',
      'summary': '',
      'body': '',
      'childPathList': [],
      'tags': []
    };

    content['path'] = contentPath;
    content['title'] = document.getElementById('title-input').value;
    content['createdAt'] = document.getElementById('created-at-input').value;
    content['parentPath'] = document.getElementById('parent-input').value;
    content['summary'] = Indent(summaryEditor.session.getValue(), 2);
    content['body'] = bodyEditor.session.getValue();
    childrenInput = document.getElementById('children-input').value;
    childrenLines = childrenInput.split("\n");
    for (i = 0; i < childrenLines.length; i++) {
      childPath = childrenLines[i].trim();
      if (childPath != "") {
        content['childPathList'].push(childPath);
      }
    }
    tagListInput = document.getElementById('tag-list').children;
    for (i = 0; i < tagListInput.length; i++) {
      tag = tagListInput[i].getAttribute('name');
      if (tag != "") {
        content['tags'].push(tag);
      }
    }
    jsonContent = JSON.stringify(content);
    //alert(jsonContent);

    alert("Save content.")
    if (!window.confirm('Are you sure?')) {
      return;
    }

    openTime = document.getElementById('openTime').value;

    window.onbeforeunload = null;

    form = document.createElement('form');
    form.setAttribute('action', '<?=SERVICE_URI?>/contents-database-edit-service.php');
    form.setAttribute('method', 'POST'); // POSTリクエストもしくはGETリクエストを書く。
    form.style.display = 'none'; // 画面に表示しないことを指定する
    document.body.appendChild(form);

    data = {
      "cmd": "SaveContentFile",
      "token": token,
      "content": jsonContent,
      "openTime": openTime
    };

    if (data !== undefined) {
      Object.keys(data).map((key) => {
        let input = document.createElement('input');
        input.setAttribute('type', 'hidden');
        input.setAttribute('name', key); //「name」は適切な名前に変更する。
        input.setAttribute('value', data[key]);
        form.appendChild(input);
      })
    }
    form.submit();
    // console.log(form)
    return;
  }

  function Unindent(text, level) {
    text = text.replace("\r", "");

    lines = text.split("\n");
    for (i = 0; i < lines.length; i++) {
      for (j = 0; j < lines[i].length; j++) {
        if (lines[i][j] != ' ') {
          break;
        }
      }

      if (j >= level * 4) {
        j = level * 4;
      }

      lines[i] = lines[i].slice(j);
    }

    return lines.join("\n");
  }

  function Indent(text, level) {
    text = text.replace("\r", "");

    lines = text.split("\n");

    spaces = "";
    for (i = 0; i < level; i++) {
      spaces += "    ";
    }

    for (i = 0; i < lines.length; i++) {
      lines[i] = spaces + lines[i];
    }

    return lines.join("\n");
  }
  </script>
</body>

</html>