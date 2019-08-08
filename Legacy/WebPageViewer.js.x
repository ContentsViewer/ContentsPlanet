/*
*WebPageViewer
*   最終更新日:
*       10.5.2016
*
*   説明:
*       WebpageViewerが用いるJavaScriptです
*
*   更新履歴:
*       8.24.2016:
*           プログラムの完成
*
*       8.25.2016:
*           各Contentの最終更新日は自動で出るようにした
*
*       8.27.2016:
*           Abstract, RootContent内においてScriptが実行できるようになった.
*
*       8.28.2016:
*           関数情報, 変数情報を描画する関数を追加
*
*       9.9.2016:
*           ブラウザの戻る進むボタンに対応
*           URLから指定されたContentに直接行くことが可能
*           不要なイベント処理を削除, 処理速度を改善
*           Page切り替わり時Page先頭から始まらない問題を修正
*
*       9.10.2016:
*           ContentのTitleをページのTitleに表示するようにした
*
*       10.5.2016:
*           GUI関係処理を分離
*           ChildrenとParent間のリンクにaタグを用いた; SEO対策
*           Client側でのContentData構造を変更
*
*       10.6.2016:
*           Error情報のデータ構造を変更
*           ContentsDataBaseとの通信時間が長いときにMessageを表示するようにした
*/
/*
$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$
$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$
$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$
$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$
$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$
$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$
$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$
$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$
$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$--$$$$$$$$8-7$--7$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$
$$$$$$$$$$$$$$$$$$$$$$$$$3$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$3--$$$$$$$--$$83$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$
$8-=$$$=-7$$$--$$$$$$$$$$-7$$$$$$3------3$$$$$$$$$$$$$$$$$$$$$$$$$$$2-+$$$$$--8$$=-2$$$+-----=8$7-7$$$8--$$$$--$$8+-----=8$$=-3=---$$$
$$7-8$$-+-$$8-8$$3====8$$-++--+$$3-2$$$7-$3----+$$$3=--+-3$$2==-+8$$$--7$$$--7$$$--2$$--$$$$$--$$--$$$---7$$7-7$$--$$$$$--$$--7$$$$$$$
$$$-+$++$-7$-+$$--8$$8-3$--3$8=-$3-=77=-7$$$$82--$2-2$$3-7$-=$$$2-3$$$--$$3-=$$$$--2$7--=====--3$3-7$+-87-3$--$$7--=====--3$--$$$$$$$$
$$$3-3-3$3-2-$$$--=====3$-7$$$3-$3-=773$$$+-=+7--$-=$$$$-7$--=====3$$$8--3--$$$$$--2$2-+$$$$$$$$$$=-2-3$$----3$$2-+$$$$$$$$$--$$$$$$$$
$$$$---$$$--=$$$7-=333=8$--337-7$3-2$$$$$$-+33+--$2-732=-7$7-7332=8$$$$8---$$$$$$--2$$+--7332+-$$$8--=$$$$--+$$$$---7332+-$$--$$$$$$$$
$$$$87$$$$37$$$$$$37723$$733728$$$78$$$$$$$272833$$82732-3$$837733$$$$$$278$$$$$$378$$$82+==+28$$$$32$$$$$82$$$$$$82+==+28$$32$$$$$$$$
$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$+===-7$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$
$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$
$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$
$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$
$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$
$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$
$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$
$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$
*/

var pageBuilder = null;
var commandConverter = null;
var timer025 = null;
var timer002 = null;

function Init() {
    timer025 = new Timer(0.25);
    timer002 = new Timer(0.02);
    commandConverter = new CommandConverter();
    pageBuilder = new PageBuilder();
    pageBuilder.Begin();
}

//TimerObject
//指定された時間間隔で時を刻みます
//updateEventsに関数を登録すると毎フレームでその関数が呼ばれます
function Timer(deltaTime) {
    this.deltaTime = deltaTime;
    var time = 0.0;
    var updateEvents = new Array();

    this.AddUpdateEvents = function (event) {
        updateEvents.push(event);
    }

    this.Update = function () {
        time += deltaTime;
        for (var i = 0; i < updateEvents.length; i++) {
            updateEvents[i]();
        }
    }

    this.Stop = function () {
        clearInterval(timerID);
    }

    this.GetDeltaTime = function () {
        return deltaTime;
    }

    this.GetTime = function () {
        return time;
    }

    var timerID = setInterval(this.Update, deltaTime * 1000);
}

function PageBuilder() {

    var currentContentPath = null;

    //parentPath:
    //childPathList:
    //content:
    //waitingBuild:
    //
    var contentChunkList = {};

    var connectionCount = 0;

    var loadingBoxToDisappearTime = 0.0;
    var loadingTime = 0.0;
    var loadingTimeOut = 5.0;

    var errors = {};
    var errorBoxDefaultSize = 30;
    var errorBoxDeltaPos = 10;
    var errorBoxFirstPosX = 10;
    var shownErrorBoxsCount = 0;
    var errorBoxToShowList = {};
    var errorBoxToRemoveList = {};
    var errorBoxs = [];

    //前フレームでのブラウザアドレスバーの内容
    var locationURLPrev = "";

    //このObjectをさす変数
    var me = this;

    this.Begin = function () {
        timer025.AddUpdateEvents(this.Update025);
        timer002.AddUpdateEvents(this.Update002);

    }

    this.GetErrors = function () {
        return errors;
    }

    this.AddError = function (name, mes) {
        if (errors[name] == null) {
            errors[name] = {};

            //同じnameを持つものがすでにないときのみ, 表示に入るためのlistに登録する
            if (errorBoxToShowList[name] == null) {
                errorBoxToShowList[name] = true;
            }
        }
        errors[name].message = mes;
    }

    this.RemoveError = function (name) {
        if (errors[name] != null) {
            delete errors[name];

            if (errorBoxToRemoveList[name] == null) {
                errorBoxToRemoveList[name] = true;
            }

            if (Object.keys(errors).length <= 0) {
                me.ClearErrorMessageBox();
            }
        }
    }

    //
    //関数:
    //  説明:
    //      指定されたContentPathを持つContentを設定します.
    //      設定されたContentの情報は完全です.
    //
    this.SetContent = function (contentPath) {

        var form = new FormData();
        form.append("Command", commandConverter.GetContentFromFile(contentPath));

        var xhr = new XMLHttpRequest();
        xhr.open("POST", "/ContentsDataBase.php", true);
        xhr.responseType = "json";
        xhr.onload = function (e) {
            if (this.status == 200) {
                connectionCount--;
                var received = this.response;
                if (!me.CheckResponse(received)) {
                    return;
                }
                me.SetContentChunk(contentPath);
                contentChunkList[contentPath].content = received;
                contentChunkList[contentPath].waitingBuild = true;
                me.SetChildren(contentPath);
                me.SetParent(contentPath);
            }
        };

        //送信
        xhr.send(form);
        connectionCount++;
    }

    //
    //関数:
    //  説明:
    //      指定されたContentPathを持つContentの子Contentsを設定します.
    //      設定されたContentの情報は不完全です.
    //
    this.SetChildren = function (contentPath) {

        //children情報初期化
        contentChunkList[contentPath].childPathList = [];

        for (var i = 0; i < contentChunkList[contentPath].content.children.length; i++) {

            var form = new FormData();
            form.append("Command", commandConverter.GetChild(contentChunkList[contentPath].content, i));

            var xhr = new XMLHttpRequest();
            xhr.open("POST", "/ContentsDataBase.php", true);
            xhr.responseType = "json";
            xhr.onload = function (e) {
                if (this.status == 200) {
                    connectionCount--;
                    var received = this.response;
                    if (!me.CheckResponse(received)) {
                        return;
                    }

                    me.SetContentChunk(received.path);
                    me.SetContentChunk(contentPath);
                    contentChunkList[contentPath].childPathList[received.index] = received.path;
                    contentChunkList[received.path].content = received;
                    contentChunkList[received.path].parentPath = contentPath;
                    contentChunkList[received.path].waitingBuild = true;
                }
            };

            //送信
            xhr.send(form);
            connectionCount++;
        }
    }

    //
    //関数:
    //  説明:
    //      指定されたContentPathを持つContentの親Contentを設定します.
    //      設定されたContentの情報は不完全です.
    //
    this.SetParent = function (contentPath) {
        //Parent情報初期化
        me.SetContentChunk(contentPath);
        contentChunkList[contentPath].parentPath = null;
        if (contentChunkList[contentPath].content.isRoot) {
            return;
        }

        var form = new FormData();
        form.append("Command", commandConverter.GetParent(contentChunkList[contentPath].content));

        var xhr = new XMLHttpRequest();
        xhr.open("POST", "/ContentsDataBase.php", true);
        xhr.responseType = "json";
        xhr.onload = function (e) {
            if (this.status == 200) {
                connectionCount--;
                var received = this.response;
                if (!me.CheckResponse(received)) {
                    return;
                }
                me.SetContentChunk(contentPath);
                me.SetContentChunk(received.path);
                contentChunkList[contentPath].parentPath = received.path;
                contentChunkList[received.path].content = received;
                contentChunkList[received.path].waitingBuild = true;
            }
        };

        //送信
        xhr.send(form);
        connectionCount++;
    }

    //
    //関数
    //  説明:
    //      Serverからの送られた情報が正常か確認します.
    //
    //  返り値:
    //      true:
    //          正常
    //
    //      false:
    //          問題あり
    //
    this.CheckResponse = function (response) {
        if (response == null) {
            var mes = "<h1>Error!</h1> ContentsDataBaseとの通信に何か問題が起こりました.";
            me.AddError("Connection", mes);
            return false;
        }

        if (response.type == "Error") {
            var mes = "<h1>Error!</h1>" + response.message + "<br>" +
            "存在しないContentにアクセスした可能性があります.";
            me.AddError("Connection", mes);
            return false;
        }


        return true;
    }

    //
    //関数:
    //  説明:
    //      ブラウザアドレスバーからクエリリストを取得します
    //
    this.QueryListGetter = function () {
        var queryList = [];
        var tmp = location.search;
        if (tmp != "") {
            tmp = tmp.substring(1, tmp.length);
            var tmp2 = tmp.split("&");
            for (i in tmp2) {
                var key = tmp2[i].split("=")[0];
                var value = tmp2[i].split("=")[1];
                queryList[key] = value;
            }
        }

        return queryList;
    }

    //
    //関数
    //  説明:
    //      指定されたクエリのリストに基づいてブラウザアドレスバーの内容を更新します
    //
    //  補足:
    //      現バージョンでは, 更新される前のハッシュ値は破棄されます.
    //
    this.QueryListSetter = function (queryList) {
        queryListStr = "?";
        var first = true;
        for (key in queryList) {
            if (first) {
                first = false;
            }
            else {
                queryListStr += "&";
            }
            queryListStr += key + "=" + queryList[key];
        }

        var url = location.protocol + "//" + location.host + location.pathname + queryListStr;

        history.pushState(null, null, url);
    }

    //
    //関数:
    //  説明:
    //      クエリをチェックします.
    //      クエリの内容に基づいてページを更新します
    //
    this.QueryChecker = function () {
        var queryList = me.QueryListGetter();
        var contentPath = "Contents/Root.html";
        if (queryList["contentPath"] != null) {
            contentPath = queryList["contentPath"];
        }

        if ((currentContentPath != null && currentContentPath != contentPath) ||
            currentContentPath == null) {
            me.ClearContentPage();
            me.SetContent(contentPath);
            currentContentPath = contentPath;
        }
    }

    this.CreateHREF = function (contentPath) {
        return "/?contentPath=" + contentPath;
    }

    this.SetContentChunk = function (contentPath) {
        if (contentChunkList[contentPath] == null) {
            contentChunkList[contentPath] = {};
        }
    }

    this.Update025 = function () {

        //ブラウザアドレスバーの内容が変わったとき
        if (locationURLPrev != location.href) {
            me.QueryChecker();
        }
        locationURLPrev = location.href;

        me.BuildContentPage();
    }

    this.Update002 = function () {
        //Loading中のAnimation処理
        me.DrawLoadingBox();

        //Errorが出た時の処理
        me.DrawErrorBoxs();
    }

    this.DrawLoadingBox = function () {
        //ContentsDataBaseからの応答待ちのとき
        if (connectionCount > 0) {
            loadingBoxToDisappearTime = 1.0;
            loadingTime += timer002.GetDeltaTime();
            if (loadingTime >= loadingTimeOut) {
                var mes = "<h1>Sorry...</h1> ContentsDataBaseとの通信時間が長すぎます.<br>" +
                    "このままお待ちいただくか, ブラウザのリロードボタンを押してください.";
                me.AddError("TimeOut", mes);
            }
        }
        else {
            loadingBoxToDisappearTime -= timer002.GetDeltaTime();
            loadingTime = 0.0;
            me.RemoveError("TimeOut");
            if (loadingBoxToDisappearTime < 0.0) {
                loadingBoxToDisappearTime = 0.0;
            }
        }

        //LoadingBoxを取得
        var loadingBox = document.querySelector("#LoadingBox");
        if (loadingBoxToDisappearTime > 0.0) {

            loadingBox.style.backgroundColor = "rgba(0, 102, 255, 0.5)";

            var x = Math.cos(timer002.GetTime() * 4);
            var y = Math.sin(timer002.GetTime() * 4);
            //loadingBox.style.width = 50 - (25 * x) + "px";
            loadingBox.style.width = 25 + (25 * x) + "px";
            loadingBox.style.height = 25 + (25 * x) + "px";
            //loadingBox.style.right = 10 - (10 * y) + "px";
            loadingBox.style.right = 10 + (10 * y) + "px";
            loadingBox.style.bottom = 10 + (10 * y) + "px";
        }
        else {
            loadingBox.style.backgroundColor = "transparent";
        }
    }

    this.DrawErrorBoxs = function () {
        //現在表示中のerrorBoxに対する処理
        for (var i = 0; i < errorBoxs.length; i++) {
            var t = timer002.GetTime() % 1;
            var x = t * Math.sqrt(1 - t * t);
            var a = errorBoxDefaultSize * 0.3;
            errorBoxs[i].style.width = errorBoxDefaultSize + a * x + "px";
            errorBoxs[i].style.height = errorBoxDefaultSize + a * x + "px";
            errorBoxs[i].style.backgroundColor = "rgba(255, 51, 0, 0.7)";
            if (errorBoxToRemoveList[errorBoxs[i].name] != null) {
                errorBoxs[i].parentNode.removeChild(errorBoxs[i]);
                delete errorBoxToRemoveList[errorBoxs[i].name];
                errorBoxs.splice(i, 1);
                shownErrorBoxsCount--;
            }
        }

        //表示される予定のerrorBoxに対する処理
        for (var toShow in errorBoxToShowList) {
            var newBox = document.createElement("p");
            newBox.style.bottom = "10px";
            newBox.style.left = errorBoxFirstPosX + (errorBoxDefaultSize + errorBoxDeltaPos) * shownErrorBoxsCount + "px";
            newBox.className = "ErrorBox";
            newBox.name = toShow;
            newBox.addEventListener("mouseover", OnMouseOverErrorBox);
            newBox.addEventListener("mouseout", OnMouseOutErrorBox);
            document.body.appendChild(newBox);
            errorBoxs.push(newBox);
            delete errorBoxToShowList[toShow];
            shownErrorBoxsCount++;
        }
    }

    this.ClearErrorMessageBox = function () {
        //MessageBoxを探して削除する
        var mesBox = document.querySelector("#ErrorMessageBox");
        if (mesBox != null) {
            mesBox.parentNode.removeChild(mesBox);
        }
    }

    this.ClearContentPage = function () {
        var titleField = document.querySelector("#TitleField");
        titleField.innerHTML = "";
        document.title = "";

        //最終更新日欄設定
        var lastUpdatedField = document.querySelector("#LastUpdatedField");
        lastUpdatedField.innerHTML = "";

        //Abstract設定
        var abstractField = document.querySelector("#AbstractField");

        //Abstract内の要素をすべて消去
        while (abstractField.firstChild) {
            abstractField.removeChild(abstractField.firstChild);
        }

        //RootContent設定
        var rootContentField = document.querySelector("#RootContentField");

        //RootContent内の要素をすべて消去
        while (rootContentField.firstChild) {
            rootContentField.removeChild(rootContentField.firstChild);
        }

        //子コンテンツの一覧をすべて消去
        var childrenField = document.querySelector("#ChildrenField");
        while (childrenField.firstChild) {
            childrenField.removeChild(childrenField.firstChild);
        }

        //Parent消去
        var parentField = document.querySelector("#ParentField");
        while (parentField.firstChild) {
            parentField.removeChild(parentField.firstChild);
        }

    }

    this.BuildContentPage = function () {
        if (currentContentPath != null && contentChunkList[currentContentPath] != null) {
            var currentContent = contentChunkList[currentContentPath].content;

            if (contentChunkList[currentContentPath].waitingBuild) {
                var titleField = document.querySelector("#TitleField");
                titleField.innerHTML = currentContent.title;
                document.title = currentContent.title;

                //最終更新日欄設定
                var lastUpdatedField = document.querySelector("#LastUpdatedField");
                lastUpdatedField.innerHTML = "最終更新日: " + currentContent.lastUpdated;

                //Abstract設定
                var abstractField = document.querySelector("#AbstractField");
                var abstracttDoc = this.CreateHTMLElements(currentContent.abstract);
                abstractField.appendChild(abstracttDoc);

                //RootContent設定
                var rootContentField = document.querySelector("#RootContentField");
                var rootContentDoc = this.CreateHTMLElements(currentContent.rootContent);
                rootContentField.appendChild(rootContentDoc);

                contentChunkList[currentContentPath].waitingBuild = false;
            }

            var childrenField = document.querySelector("#ChildrenField");
            var childPathList = contentChunkList[currentContentPath].childPathList;
            for (var i = 0; i < childPathList.length; i++) {
                if (childPathList[i] != null && contentChunkList[childPathList[i]] != null &&
                    contentChunkList[childPathList[i]].waitingBuild) {
                    var child = contentChunkList[childPathList[i]].content;

                    var childField = document.createElement("a");
                    childField.className = "LinkButtonBlock";
                    childField.href = me.CreateHREF(child.path);
                    childField.innerHTML = child.title;
                    if (childrenField.childNodes.length <= i) {
                        childrenField.appendChild(childField);
                    }
                    else {
                        childrenField.insertBefore(childField, childrenField.childNodes[i]);
                    }

                    contentChunkList[childPathList[i]].waitingBuild = false;
                }
            }

            //Parent設定
            var parentField = document.querySelector("#ParentField");
            if (contentChunkList[currentContentPath].parentPath != null &&
                contentChunkList[contentChunkList[currentContentPath].parentPath] != null &&
                contentChunkList[contentChunkList[currentContentPath].parentPath].waitingBuild) {
                var linkField = document.createElement("a");
                linkField.href = me.CreateHREF(contentChunkList[currentContentPath].parentPath);
                linkField.innerHTML = contentChunkList[contentChunkList[currentContentPath].parentPath].content.title;
                parentField.appendChild(linkField);
                contentChunkList[contentChunkList[currentContentPath].parentPath].waitingBuild = false;
            }
        }
    }

    this.CreateHTMLElements = function (source) {
        //一時的に要素を作る; DOMTreeを作るためのもの
        var div = document.createElement("div");
        div.innerHTML = source;
        var fragment = document.createDocumentFragment();
        this.ConvertChildrenToFragment(div, fragment);
        return fragment;
    }

    this.ConvertChildrenToFragment = function (node, fragment) {
        var chs = node.childNodes;
        for (var i = 0, len = chs.length; i < len; i++) {
            //alert(chs[i].nodeName);
            if (chs[i].nodeName.toLowerCase() === "script") {
                var script = document.createElement("script");
                if (chs[i].type) {
                    script.type = chs[i].type;
                }

                if (chs[i].src) {
                    script.src = chs[i].src;
                }

                script.text = chs[i].text;
                fragment.appendChild(script);
            }
            else {

                this.ConvertChildrenToFragment(chs[i].cloneNode(true), fragment.appendChild(chs[i].cloneNode(false)));
            }
        }
    }
}

function OnMouseDown(event) {
    switch (event.buttons) {
        case 1:
            var elements = event.target.name.split(",");
            switch (elements[0]) {
            }
            break;
    }
}

function OnTouchStart(event) {
    var touch = event.touches[0];
    var elements = touch.target.name.split(",");
    switch (elements[0]) {
    }
}

//
//関数:
//  説明:
//      マウスポインタがErrorBoxに入ったとき
//
function OnMouseOverErrorBox(event) {
    if (event.target.className == "ErrorBox") {
        var mesBox = document.querySelector("#ErrorMessageBox");
        if (mesBox == null) {
            var errors = pageBuilder.GetErrors();
            var errorMessageBox = document.createElement("p");
            errorMessageBox.id = "ErrorMessageBox";
            errorMessageBox.innerHTML = errors[event.target.name].message;
            document.body.appendChild(errorMessageBox);
        }
    }
}

//
//関数:
//  説明:
//      マウスポインタがErrorBoxを出たとき
//
function OnMouseOutErrorBox(event) {
    if (event.target.className == "ErrorBox") {
        pageBuilder.ClearErrorMessageBox();
    }
}

function CommandConverter() {
    this.GetContentFromFile = function (filePath) {
        var work = { "header": "GetContentFromFile", "filePath": filePath };
        return JSON.stringify(work);
    }

    this.GetChild = function (content, index) {
        var work = { "header": "GetChild", "index": index, "content": content };
        return JSON.stringify(work);
    }

    this.GetParent = function (content) {
        var work = { "header": "GetParent", "content": content };
        return JSON.stringify(work);
    }
}