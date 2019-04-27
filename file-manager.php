<?php

// require_once dirname(__FILE__) . "/ContentsManagementSystem.php";
require_once dirname(__FILE__) . "/Module/Authenticator.php";

Authenticator::RequireLoginedSession();

header('Content-Type: text/html; charset=UTF-8');

require_once dirname(__FILE__) . "/Module/ContentsDatabaseManager.php";
require_once dirname(__FILE__) . "/Module/Tips.php";

$rootContentPath = ContentsDatabaseManager::GetRelatedRootFile(Authenticator::GetContentsFolder() . '/');
ContentsDatabaseManager::LoadRelatedTagMap($rootContentPath);

?>


<!DOCTYPE html>
<html lang="ja">

<head>
    <?php readfile("Client/Common/CommonHead.html");?>

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

#loading-box{
    position: fixed;
    right: 5px;
    bottom: 5px;
    z-index: 99;
}

.spinner{
    display: inline-block;
    height: 40px;
    width: 40px;
    position: relative;
}

.cube1, .cube2{
    background-color: rgba(0, 102, 255, 0.5);
    animation: poping-plane 1.8s infinite alternate ease-in-out;
    width: 80%;
    height: 80%;
    position: absolute;
}

.cube2{
    animation-delay: -0.9s;
}
@keyframes poping-plane {
    0%{
        transform: translateX(0%) translateY(0%) scale(1.2) rotate(0deg);
    }
    50%{
        transform: translateX(70%) translateY(70%) scale(0.0) rotate(180deg);
    }
    100%{
        transform: translateX(0%) translateY(0%) scale(1.2) rotate(360deg);

    }
}

#remaining{
    font-size: 0.7em;
    opacity: 0.5;
}
.button {
    cursor: pointer;
    font-size: 0.8em;
    border: 1px solid #15aeec;
    background-color: #49c0f0;
    background-image: -webkit-linear-gradient(top, #49c0f0, #2cafe3);
    background-image: linear-gradient(to bottom, #49c0f0, #2cafe3);
    border-radius: 4px;
    color: #fff;
    padding: 10px;
    margin: 10px;
    /* line-height: 40px; */
    -webkit-transition: none;
    transition: none;
    text-shadow: 0 1px 1px rgba(0, 0, 0, .3);
}
.button:not(.uninteractable):hover {
    border:1px solid #1090c3;
    background-color: #1ab0ec;
    background-image: -webkit-linear-gradient(top, #1ab0ec, #1a92c2);
    background-image: linear-gradient(to bottom, #1ab0ec, #1a92c2);
}
.button:active, .uninteractable {
    background: #1a92c2;
    box-shadow: inset 0 3px 5px rgba(0, 0, 0, .2);
    color: #1679a1;
    text-shadow: 0 1px 1px rgba(255, 255, 255, .5);
}
.uninteractable{
    cursor: not-allowed;
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

    <div id='loading-box'>
        <span id='remaining'></span>
        <div class='spinner'>
            <div class='cube1'></div>
            <div class='cube2'></div>
        </div>
    </div>

    <input type="hidden" id="token" value="<?=Authenticator::H(Authenticator::GenerateCsrfToken())?>">
    <p id='logout'><a href="./logout.php?token=<?=Authenticator::H(Authenticator::GenerateCsrfToken())?>">ログアウト</a></p>

    <h2>Contents</h2>
    <div id='content-tree'></div>

    <hr>
    <select id='tag-list'>
        <?php
foreach (Content::GlobalTagMap() as $tagName => $pathList) {
    echo "<option>" . $tagName . "</option>";
}
        ?>
    </select>

    <span class='open' onclick=OpenTaggedFile()>→</span>
    <ul id='tagged-content-list'></ul>
    <hr>

    <div style='display: flex; justify-content: space-around;'>
        <div class='button' onclick='UpdateTagMap(event);'>タグマップの更新</div>
        <div class='button' onclick='UpdateContentsFolder(event);'>コンテンツフォルダの更新</div>
    </div>
    <hr>
    <script src="Client/FileManager/FileManager.js" type="text/javascript" charset="utf-8"></script>

    <script>

        var token = document.getElementById('token').value;
        var contentManager = new FileManager(document.getElementById('content-tree'),
                                        '<?=Authenticator::GetContentsFolder($_SESSION['username'])?>',
                                        ['.content', '.png', '.jpg', '.gif', '.zip', '.bmp', '.txt', '.data', '.pdf', '.html'], false, token,
                                        OpenFile, './Home/', CopyPathText,
                                        SendRequestCallbackFunction,
                                        ReceiveResponseCallbackFunction);

        function CopyContentPathText(fileElement){
            // if(fileElement.isFolder){
            //     // return fileElement.path;
            //     return './Home' + fileElement.path.slice(1);
            // }

            return './?content=' + FileManager.RemoveExtention(fileElement.path);
            // return FileManager.RemoveExtention(fileElement.path);
        }

        function CopyFilePathText(fileElement){
            // if(fileElement.isFolder){
            //     return fileElement.path;
            // }

            return './Home' + fileElement.path.slice(1);
        }

        function CopyPathText(fileElement){
            if(FileManager.GetExtention(fileElement.path) == '.content'){
                return CopyContentPathText(fileElement);
            }
            else{
                return CopyFilePathText(fileElement);
            }

            return fileElement.path;
        }


        function OpenFile(path){
            if(FileManager.GetExtention(path) == '.content'){
                path = FileManager.RemoveExtention(path);
                window.open("./index.php?content=" + path);
            }
            else{
                window.open('./Home/' + path);
            }
        }

        function OpenTaggedContentFile(){
            OpenFile(this.fileElement.path);
            // alert(this);
        }

        function UpdateContentsFolder(event){
            if(event.target.classList.contains('uninteractable')){
                return;
            }

            event.target.classList.add('uninteractable');

            var form = new FormData();
            form.append("cmd", "UpdateContentsFolder");
            form.append("token", token);
            
            var xhr = new XMLHttpRequest();
            xhr.open("POST", "Service/contents-database-edit-service.php", true);
            xhr.responseType = "json";
            xhr.button = event.target;
            
            xhr.onload = function (e) {
                requestCount--;
                alert("Successfully update contents folder.");

                this.button.classList.remove('uninteractable');
            }
            
            //送信
            xhr.send(form);
            requestCount++;
        }

        function UpdateTagMap(event){
            if(event.target.classList.contains('uninteractable')){
                return;
            }

            event.target.classList.add('uninteractable');

            var form = new FormData();
            form.append("cmd", "UpdateTagMap");
            form.append("token", token);
            
            var xhr = new XMLHttpRequest();
            xhr.open("POST", "Service/contents-database-edit-service.php", true);
            xhr.responseType = "json";
            xhr.button = event.target;

            xhr.onload = function (e) {
                requestCount--;
                alert("Successfully update TagMap.");
                
                this.button.classList.remove('uninteractable');
            }
            
            //送信
            xhr.send(form);
            requestCount++;
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
                requestCount--;

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
                        'copyPathTextCallbackFunction': CopyPathText

                    });
                    taggedFileList.appendChild(file.element);

                    //alert(content);
                }

            };

            //送信
            xhr.send(form);
            requestCount++;
        }

        var requestCount = 0;

        function SendRequestCallbackFunction(request){
            requestCount++;
        }

        function ReceiveResponseCallbackFunction(request){
            requestCount--;
        }

        var timerId = setTimeout(Update, 1000);

        function Update(){
            var loadingBox = document.getElementById('loading-box');
            var remaining = document.getElementById('remaining');

            if(requestCount > 0){
                //alert("12");
                loadingBox.style.visibility = '';
                remaining.textContent = requestCount;
                timerId = setTimeout(Update, 1000);
            }
            else{
                loadingBox.style.visibility = 'hidden';
                timerId = setTimeout(Update, 500);
            }
        }


    </script>

</body>
</html>