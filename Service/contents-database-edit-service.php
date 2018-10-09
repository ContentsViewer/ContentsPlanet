<?php


require_once dirname(__FILE__) . "/../Module/Authenticator.php";

Authenticator::RequireLoginedSession();


header ('Content-Type: text/html; charset=UTF-8');


require_once dirname(__FILE__) . "/../Module/ContentsDatabaseManager.php";
require_once dirname(__FILE__) . "/../Module/Debug.php";
require_once dirname(__FILE__) . "/../Module/php-diff-master/lib/Diff.php";
require_once dirname(__FILE__) . "/../Module/php-diff-master/lib/Diff/Renderer/Html/SideBySide.php";


function SendErrorResponseAndExit($response, $error){
    $response['error'] = $error;
    SendResponseAndExit($response);
}

function SendResponseAndExit($response){
    echo json_encode($response);
    exit;
}



if($_SERVER['REQUEST_METHOD'] !== 'POST'){
    
    exit;
}


if(!isset($_POST['token']) || !Authenticator::ValidateCsrfToken($_POST['token'])){
    SendResponseAndExit(null);
}



if(!isset($_POST['cmd'])){
    SendResponseAndExit(null);
}

$rootContentPath = ContentsDatabaseManager::GetRelatedRootFile(Authenticator::GetContentsFolder() . '/');
$metaFileName = ContentsDatabaseManager::GetRelatedMetaFileName(Authenticator::GetContentsFolder() . '/');

$cmd = $_POST['cmd'];




if($cmd === 'GetGlobalTagList'){
    ContentsDatabaseManager::LoadRelatedTagMap($rootContentPath);

    echo json_encode(Content::GlobalTagMap());
    exit;
}

elseif($cmd === 'GetTaggedContentList' &&
       isset($_POST['tagName'])){

    $tagName = $_POST['tagName'];
    $response = ["isOk" => true, "tagName" => $tagName, "contentList" => []];

    ContentsDatabaseManager::LoadRelatedTagMap($rootContentPath);


    if(array_key_exists($tagName, Content::GlobalTagMap())){
        $response["contentList"] = Content::GlobalTagMap()[$tagName];
    }

    SendResponseAndExit($response);
}

elseif($cmd === 'SaveContentFile' && 
    (isset($_POST['content']) || (isset($_POST['path']) && isset($_POST['contentFileString']))) &&
     isset($_POST['openTime'])){

    $contentFileString = "";
    $path = "";

    if(isset($_POST['content'])){

        $mappedContent = json_decode($_POST['content'], true);

            
        $content = new Content();

        $content->SetPath($mappedContent["path"]);
        $content->SetTitle($mappedContent["title"]);
        $content->SetCreatedAt($mappedContent["createdAt"]);
        $content->SetParentPath($mappedContent["parentPath"]);
        $content->SetSummary($mappedContent["summary"]);
        $content->SetBody($mappedContent["body"]);
        $content->SetChildPathList($mappedContent["childPathList"]);
        $content->SetTags($mappedContent["tags"]);

        $contentFileString = $content->ToContentFileString();

        $path = $mappedContent["path"];
    }
    else{
        $contentFileString = $_POST['contentFileString'];
        $path = $_POST['path'];
    }

    $contentFileString = str_replace("\r", "", $contentFileString);

    $openTime = $_POST['openTime'];
    $updatedTime = filemtime(Content::RealPath($path));
    

    if($openTime > $updatedTime){

        file_put_contents(Content::RealPath($path),
                         $contentFileString, LOCK_EX);

    
        Content::CreateGlobalTagMap($rootContentPath);
        Content::SaveGlobalTagMap($metaFileName);

        header('Location: ../?content=' . $path);
        
        exit;
    }

    $oldContent = new Content();
    $oldContent->SetContent($path);
    RenderDiffEdit($path, $oldContent->ToContentFileString(), $contentFileString);

    exit;

}

SendResponseAndExit(null);

?>












<?php
function RenderDiffEdit($path, $oldContentFileString, $newContentFileString){
    $diff = new Diff(explode("\n", $oldContentFileString),
                     explode("\n",  $newContentFileString ) );
    $diffRenderer = new Diff_Renderer_Html_SideBySide;
	
    ?>
    


<!DOCTYPE html>
<html lang="ja">

<head>
    <title>競合解消</title>
    <style type="text/css" media="screen">
        body {
            overflow: hidden;
        }
        
        #editor{
            margin: 0;
            position: absolute;
            top: 0;
            bottom: 5%;
            left: 40%;
            right: 0;
        }

        #diff{
            overflow-y: scroll;
            overflow-x: scroll;
            margin: 0;
            position: absolute;
            top: 0;
            bottom: 5%;
            left: 0;
            right: 60%;
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
        }

                
        .Differences {
            width: 100%;
            border-collapse: collapse;
            border-spacing: 0;
            empty-cells: show;
        }

        .Differences thead th {
            text-align: left;
            border-bottom: 1px solid #000;
            background: #aaa;
            color: #000;
            padding: 4px;
        }
        .Differences tbody th {
            text-align: right;
            background: #ccc;
            width: 4em;
            padding: 1px 2px;
            border-right: 1px solid #000;
            vertical-align: top;
            font-size: 13px;
        }

        .Differences td {
            padding: 1px 2px;
            font-family: Consolas, monospace;
            font-size: 13px;
        }

        .DifferencesSideBySide .ChangeInsert td.Left {
            background: #dfd;
        }

        .DifferencesSideBySide .ChangeInsert td.Right {
            background: #cfc;
        }

        .DifferencesSideBySide .ChangeDelete td.Left {
            background: #f88;
        }

        .DifferencesSideBySide .ChangeDelete td.Right {
            background: #faa;
        }

        .DifferencesSideBySide .ChangeReplace .Left {
            background: #fe9;
        }

        .DifferencesSideBySide .ChangeReplace .Right {
            background: #fd8;
        }

        .Differences ins, .Differences del {
            text-decoration: none;
        }

        .DifferencesSideBySide .ChangeReplace ins, .DifferencesSideBySide .ChangeReplace del {
            background: #fc0;
        }

        .Differences .Skipped {
            background: #f7f7f7;
        }

        .DifferencesInline .ChangeReplace .Left,
        .DifferencesInline .ChangeDelete .Left {
            background: #fdd;
        }

        .DifferencesInline .ChangeReplace .Right,
        .DifferencesInline .ChangeInsert .Right {
            background: #dfd;
        }

        .DifferencesInline .ChangeReplace ins {
            background: #9e9;
        }

        .DifferencesInline .ChangeReplace del {
            background: #e99;
        }

        pre {
            /* width: 100%;
            overflow: auto; */
        }
    </style>
</head>
<body>
    <input type="hidden" id="token" value="<?=Authenticator::H(Authenticator::GenerateCsrfToken())?>"> 
    <input type="hidden" id="contentPath" value="<?=$path?>">
    <input type="hidden" id="openTime" value="<?=time()?>">

    <p id='logout'><a href="../logout.php?token=<?=Authenticator::H(Authenticator::GenerateCsrfToken())?>">ログアウト</a></p>

    <pre id="diff"><?=$diff->render($diffRenderer);?></pre>

    <pre id="editor"><?=htmlspecialchars($newContentFileString, ENT_QUOTES);?></pre>

    
    <div class='save' onclick=SaveContentFile()>SAVE</div>
 

    <script src="../Client/Splitter/Splitter.js" type="text/javascript" charset="utf-8"></script>


    <script src="../Client/ace/src-min/ace.js" type="text/javascript" charset="utf-8"></script>
    <script>
        alert("ページ編集中にファイルが変更されたようです. 差分を確認して再保存してください.");

        token = document.getElementById('token').value;
        contentPath = document.getElementById('contentPath').value;

        var editor = ace.edit("editor");
        InitEditor(editor);

        var splitter = new Splitter(Splitter.Direction.Vertical, 
                                    document.getElementById('diff'),
                                    document.getElementById('editor'),
                                    {'percent': 60, 'rect': new Rect(new Vector2(0, 0), new Vector2(100, 95)),
                                    'onResizeElementBCallbackFunc':function(){editor.resize();}});
        
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

        window.onbeforeunload = function(event){
            event = event || window.event; 
            event.returnValue = 'ページから移動しますか？';
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
            
            openTime = document.getElementById('openTime').value;

            window.onbeforeunload = null;

            form = document.createElement('form');
            form.setAttribute('action', 'contents-database-edit-service.php'); 
            form.setAttribute('method', 'POST'); // POSTリクエストもしくはGETリクエストを書く。
            form.style.display = 'none'; // 画面に表示しないことを指定する
            document.body.appendChild(form);

            data = {"cmd": "SaveContentFile", "token": token, "path": contentPath, "openTime": openTime,
            　　　　 "contentFileString": editor.session.getValue()};

            if (data !== undefined) {
            Object.keys(data).map((key)=>{
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

    </script>
    <?php
}
?>
