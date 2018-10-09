<?php

// require_once dirname(__FILE__) . "/ContentsManagementSystem.php";
require_once dirname(__FILE__) . "/Module/Authenticator.php";


Authenticator::RequireLoginedSession();


header ('Content-Type: text/html; charset=UTF-8');


require_once dirname(__FILE__) . "/Module/ContentsDatabaseManager.php";
require_once dirname(__FILE__) . "/Module/Tips.php";



$rootContentPath = ContentsDatabaseManager::GetRelatedRootFile(Authenticator::GetContentsFolder() . '/');
ContentsDatabaseManager::LoadRelatedTagMap($rootContentPath);

?>


<!DOCTYPE html>
<html lang="ja">

<head>
    <title>FileManager</title>

    
    <link type="text/css" rel="stylesheet" href="Client/FileManager/FileManager.css" />
    <style type="text/css" media="screen">


.tips {
    position: relative;
    padding:0.25em 1em;
}
.tips:before,.tips:after{ 
    content:'';
    width: 20px;
    height: 30px;
    position: absolute;
    display: inline-block;
}
.tips:before{
    border-left: solid 1px #5767bf;
    border-top: solid 1px #5767bf;
    top:0;
    left: 0;
}
.tips:after{
    border-right: solid 1px #5767bf;
    border-bottom: solid 1px #5767bf;
    bottom:0;
    right: 0;
}
.tips p {
    margin: 0; 
    padding: 0;
}

#logout{
    position: absolute;
    right: 0;
    top: 10px;
    z-index:100;
}
    </style>
</head>
<body>
    <h1>FileManager</h1>
    <p>
        ようこそ<?=Authenticator::H($_SESSION['username'])?>さん!
    </p>

    <div class='tips'>
        <?=Tips::GetTip()?>
    </div>

    <input type="hidden" id="token" value="<?=Authenticator::H(Authenticator::GenerateCsrfToken())?>"> 
    <p id='logout'><a href="./logout.php?token=<?=Authenticator::H(Authenticator::GenerateCsrfToken())?>">ログアウト</a></p>

    <h2>Contents</h2>
    <div id='content-tree'></div>

    <hr>
    <select id='tag-list'>
        <?php
            foreach(Content::GlobalTagMap() as $tagName => $pathList){
                echo "<option>" . $tagName ."</option>";
            }
        ?>
    </select>

    <span class='open' onclick=OpenTaggedFile()>→</span>

    <ul id='tagged-content-list'></ul>
    <hr>
    <h2>Files</h2>
    <div id='file-tree'></div>
    

    <script src="Client/FileManager/FileManager.js" type="text/javascript" charset="utf-8"></script>

    <script>
        
        var token = document.getElementById('token').value;
        var contentFileManager = new FileManager(document.getElementById('content-tree'),
                                        '<?=Authenticator::GetContentsFolder($_SESSION['username'])?>',
                                        ['.content'], true, token,
                                        OpenContentFile, './Home/', CopyContentPathText);

        var contentFileManager = new FileManager(document.getElementById('file-tree'),
                                        '<?=Authenticator::GetFileFolder($_SESSION['username'])?>',
                                        ['.png', '.jpg', '.gif', '.zip', '.bmp'], false, token,
                                        OpenFile, './Home/', CopyDataPathText);

        
        function CopyContentPathText(fileElement){
            if(fileElement.isFolder){
                return fileElement.path;
            }

            return './?content=' + FileManager.RemoveExtention(fileElement.path);
        }

        function CopyDataPathText(fileElement){
            if(fileElement.isFolder){
                return fileElement.path;
            }

            return './Home' + fileElement.path.slice(1);
        }

        function OpenContentFile(path){
            path = FileManager.RemoveExtention(path);
            //alert(path);
            window.open("content-editor.php?content=" + path);
        }
        
        function OpenFile(path){
            window.open('./Home/' + path);
        }

        function OpenTaggedContentFile(){
            OpenContentFile(this.fileElement.path);
            // alert(this);
        }
        

        function OpenTaggedFile(){
            
            tagName = document.getElementById('tag-list').value;
            //alert(tagName);

            
            var form = new FormData();
            form.append("cmd", "GetTaggedContentList");
            form.append("tagName", tagName);
            form.append("token", token);

            var xhr = new XMLHttpRequest();
            xhr.open("POST", "Service/contents-database-edit-service.php", true);
            xhr.responseType = "json";

            xhr.onload = function (e) {

                if (this.status != 200) {

                    return;
                }
            
                if(!this.response.isOk){

                    return;
                }
            
                taggedFileList = document.getElementById('tagged-content-list');

                while (taggedFileList.firstChild) taggedFileList.removeChild(taggedFileList.firstChild);

                for(i = 0; i < this.response.contentList.length; i++){
                    contentPath = this.response.contentList[i] + ".content";
                    
                    file = new FileElement(false, null, contentPath, {
                        'hideExtention': true,
                        'hideAddButtopn': true,
                        'hideDeleteButton': true,
                        'hideRenameButton': true,
                        'openCallbackFunction': OpenTaggedContentFile,
                        'copyPathTextCallbackFunction': CopyContentPathText

                    });
                    taggedFileList.appendChild(file.element);

                    //alert(content);
                }

                
            };

            //送信
            xhr.send(form);
        }

    </script>

</body>
</html>