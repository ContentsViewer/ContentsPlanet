<?php
require_once dirname(__FILE__) . "/../CollabCMS.php";
require_once dirname(__FILE__) . "/../Module/Authenticator.php";
require_once dirname(__FILE__) . "/../Module/ErrorHandling.php";
require_once dirname(__FILE__) . '/../Module/ServiceUtils.php';
require_once dirname(__FILE__) . "/../CollabCMS.php";
require_once dirname(__FILE__) . "/../Module/ContentsDatabaseManager.php";
require_once dirname(__FILE__) . "/../Module/Debug.php";
require_once dirname(__FILE__) . "/../Module/Utils.php";
require_once dirname(__FILE__) . "/../Module/Localization.php";

set_error_handler('ErrorHandling\PlainErrorHandler');
ServiceUtils\RequireLoginedSession();
ServiceUtils\RequirePostMethod();
ServiceUtils\ValidateCsrfToken();
ServiceUtils\RequireParams('cmd');
$cmd = $_POST['cmd'];

$username = Authenticator::GetLoginedUsername();
Authenticator::GetUserInfo($username, 'contentsFolder', $contentsFolder);

if($cmd === 'GetTaggedContentList'){
    ServiceUtils\RequireParams('tagName', 'contentPath');
    $tagName = $_POST['tagName'];
    $contentPath = $_POST['contentPath'];

    if(!Authenticator::IsFileOwner($contentPath, $username)){
        ServiceUtils\SendErrorResponseAndExit('Permission denied.');
    }

    $response = ["isOk" => true, "tagName" => $tagName, "contentList" => []];

    $metaFileName = ContentsDatabaseManager::GetRelatedMetaFileName($contentPath);
    ContentsDatabaseManager::LoadRelatedMetadata($contentPath);
    $tag2path = ContentsDatabase::$metadata['tag2path'] ?? [];
    if(array_key_exists($tagName, $tag2path)){
        $out = ContentsDatabaseManager::GetSortedContentsByUpdatedTime(array_keys($tag2path[$tagName]));

        ContentsDatabase::LoadMetadata($metaFileName);
        foreach($out['notFounds'] as $path){
            ContentsDatabase::UnregistLatest($path);
            ContentsDatabase::UnregistTag($path);
        }
        ContentsDatabase::SaveMetadata($metaFileName);
        
        $response["contentList"] = [];
        foreach($out['sorted'] as $content){
            $response["contentList"][] = $content->path;
        }
    }

    ServiceUtils\SendResponseAndExit($response);
}

elseif($cmd === 'SaveContentFile'){
    ServiceUtils\RequireParams('openTime');
    $openTime = $_POST['openTime'];

    $contentFileString = "";
    $path = "";
    if(isset($_POST['content'])) {
        $mappedContent = json_decode($_POST['content'], true);

        $content = new Content();

        $content->path = $mappedContent["path"];
        $content->title = $mappedContent["title"];
        $content->createdTimeRaw = $mappedContent["createdAt"];
        $content->parentPath = $mappedContent["parentPath"];
        $content->summary = $mappedContent["summary"];
        $content->body = $mappedContent["body"];
        $content->childPathList = $mappedContent["childPathList"];
        $content->tags = $mappedContent["tags"];

        $contentFileString = $content->ToContentFileString();
        $path = $mappedContent["path"];
    }
    else {
        ServiceUtils\RequireParams('path', 'contentFileString');
        $contentFileString = $_POST['contentFileString'];
        $path = $_POST['path'];
    }

    if(!Authenticator::IsFileOwner($path, $username)){
        ServiceUtils\SendErrorResponseAndExit('Permission denied.');
    }

    $contentFileString = str_replace("\r", "", $contentFileString);
    $updatedTime = 0;

    $realPath = ContentPathUtils::RealPath($path . Content::EXTENTION, false);
    if(file_exists($realPath)){
        $updatedTime = filemtime($realPath);
    }
    
    if($openTime > $updatedTime){
        file_put_contents($realPath, $contentFileString, LOCK_EX);
        header('Location: ' . ROOT_URI . Path2URI($path));
        exit;
    }

    RenderDiffEdit($path, file_get_contents($realPath), $contentFileString);
    exit;
}

ServiceUtils\SendErrorResponseAndExit('Unrecognized command.');


function RenderDiffEdit($path, $oldContentFileString, $newContentFileString){
    $layerName = ContentsDatabaseManager::GetRelatedLayerName($path);
    if($layerName === false){
        $layerName = DEFAULT_LAYER_NAME;
    }
    $language = $layerName;
    if(!Localization\SetLocale($language)){
        $language = 'en';
        Localization\SetLocale($language);
    }

    $contentFileName = basename($path);

    ?>
<!DOCTYPE html>
<html lang="<?=$language?>">

<head>
  <?php readfile(CLIENT_DIR . "/Common/CommonHead.html"); ?>
  <title><?=Localization\Localize('contents-database-edit-service.resolveConflicts', 'Resolve conflicts')?> | <?=$contentFileName?></title>
  
  <script type="text/javascript" src="<?=CLIENT_URI?>/ThemeChanger/ThemeChanger.js"></script>
  <style type="text/css">
      body {
          overflow: hidden;
      }

      #diff {
          position: absolute;
          bottom: 50px;
          top: 0;
          left: 0;
          right: 0;
      }
      
      #logout{
          position: absolute;
          left: 0;
          top: 95%;
          margin: 0;
          /* height: 5%; */
          z-index:100;
      }
      
      .save{
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

          z-index:99;
      }

  </style>
  
  <script src="<?=CLIENT_URI?>/Splitter/Splitter.js" type="text/javascript" charset="utf-8"></script>
  <script src="<?=CLIENT_URI?>/ace/src-min/ace.js" type="text/javascript" charset="utf-8"></script>

  <script src="<?=CLIENT_URI?>/ace-diff/ace-diff.js"></script>
  <link href="<?=CLIENT_URI?>/ace-diff/dist/ace-diff.min.css" rel="stylesheet">
  <link href="<?=CLIENT_URI?>/ace-diff/dist/ace-diff-dark.min.css" rel="stylesheet">
  
  <meta name="token" content="<?=H(Authenticator::GenerateCsrfToken())?>" />
  <meta name="content-path" content="<?=$path?>" />
  <meta name="open-time" content="<?=time()?>" />
</head>

<body>
  <input type='hidden' id='oldContent' value='<?=H($oldContentFileString, ENT_QUOTES)?>'>
  <input type='hidden' id='newContent' value='<?=H($newContentFileString, ENT_QUOTES)?>'>

  <div id='logout'>
    <a href="<?=ROOT_URI?>/Logout?token=<?=H(Authenticator::GenerateCsrfToken())?>"><?=Localization\Localize('logout', 'Log out')?></a>
  </div>

  <div id='diff'></div>

  <div class='save' onclick=SaveContentFile()>SAVE</div>

  <script>
    alert("<?=Localization\Localize('contents-database-edit-service.resolveMessage', 
    'This file has been modified while editing. Please check the difference and save again.')?>");

    var token = document.getElementsByName("token").item(0).content;
    var contentPath = document.getElementsByName("content-path").item(0).content;

    oldContent = document.getElementById('oldContent').value;
    newContent = document.getElementById('newContent').value;

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
    
    document.onkeydown = 
    function (e) {
      if (event.ctrlKey ){
        if (event.keyCode == 83){
          SaveContentFile();
          event.keyCode = 0;
          return false;
        }
      }
    }

    document.onkeypress = 
    function (e) {
      if (e != null){
        if ((e.ctrlKey || e.metaKey) && e.which == 115){
          SaveContentFile();
          return false;
        }
      }
    }

    window.onbeforeunload = function(event){
      event = event || window.event; 
      // event.returnValue = 'ページから移動しますか？';
      event.returnValue = '';
    }

    function InitEditor(editor){
      editor.setTheme("ace/theme/monokai");
      editor.getSession().setMode("ace/mode/markdown");
      editor.session.setTabSize(4);
      editor.session.setUseSoftTabs(true);
      editor.session.setUseWrapMode(false);
    }

    function SaveContentFile(){
      alert("Save content.")
      if(!window.confirm('Are you sure?')){
        return;
      }
      
      openTime = document.getElementsByName("open-time").item(0).content;

      window.onbeforeunload = null;

      form = document.createElement('form');
      form.setAttribute('action', 'contents-database-edit-service.php'); 
      form.setAttribute('method', 'POST'); // POSTリクエストもしくはGETリクエストを書く。
      form.style.display = 'none'; // 画面に表示しないことを指定する
      document.body.appendChild(form);

      data = {"cmd": "SaveContentFile", "token": token, "path": contentPath, "openTime": openTime,
      　　　　"contentFileString": differ.getEditors().right.session.getValue()};

      if (data !== undefined) {
        Object.keys(data).map((key)=>{
          let input = document.createElement('input');
          input.setAttribute('type', 'hidden');
          input.setAttribute('name', key);
          input.setAttribute('value', data[key]);
          form.appendChild(input);
        })
      }
      form.submit();
      // console.log(form)
      return;
    }

  </script>
</body>
</html>
    <?php
}