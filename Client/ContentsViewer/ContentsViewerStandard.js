// var offsetYToHideHeader = 100;
var offsetYToHideHeader = 50;

var headerArea = null;
var pullDownMenuButton = null;
var pullUpMenuButton = null;
var leftSideAreaResponsive = null;
var menuOpenInput = null;
var indexArea = null;
var warningMessageBox = null;
var isTouchDevice = false;
var sitemask = null;
var doseHideHeader = false;
var menuOpenButton = null;
var searchOverlay = null;
var searchResultsParent = null;
var searchResults = null;
var searchBoxInput = null;
var searchBoxInputClearButton = null;
var docOutlineNavi = null;
var token = "";
var contentPath = "";
var serviceUri = "";

var sectionListInMainContent = [];
var sectionListInSideArea = [];
var currentSectionIdDict = {};

var timer = null;
var scrollPosPrev = 0;

window.onload = function() {
  // 各Area取得
  headerArea = document.querySelector("#header-area");
  pullDownMenuButton = document.querySelector("#pull-down-menu-button");
  pullUpMenuButton = document.querySelector("#pull-up-menu-button");
  warningMessageBox = document.getElementById("warning-message-box");
  leftSideAreaResponsive = document.getElementById("left-side-area-responsive");
  menuOpenInput = document.getElementById("menu-open");
  sitemask = document.getElementById("sitemask");
  menuOpenButton = document.getElementsByClassName(
    "menu-open-button-wrapper"
  )[0];
  searchOverlay = document.getElementById("search-overlay");
  searchResults = document.getElementById("search-results");
  searchBoxInput = document.getElementById("search-box-input");
  searchBoxInputClearButton = document.getElementById(
    "search-box-input-clear-button"
  );
  token = document.getElementById("token").value;
  contentPath = document.getElementById("contentPath").value;
  serviceUri = document.getElementById("serviceUri").value;

  searchResultsParent = searchResults.parentNode;
  searchResultsParent.removeChild(searchResults);
  searchBoxInputClearButton.style.display = "none";

  scrollPosPrev = window.pageYOffset;

  // Scrollイベント登録
  window.addEventListener("scroll", OnScroll);

  // タッチデバイス判定
  isTouchDevice = IsTouchDevice();

  // --- 目次関係 --------------------------------------------
  var rightSideArea = document.getElementById("right-side-area");
  var docOutlineEmbeded = document.getElementById("doc-outline-embeded");
  var contentBody = document.getElementById("content-body");

  if (contentBody && rightSideArea) {
    // rightSideArea内にあるNaviを取得
    if (rightSideArea.getElementsByClassName("navi").length > 0) {
      docOutlineNavi = rightSideArea.getElementsByClassName("navi")[0];
    }

    // Naviを取得できた場合のみ実行
    if (docOutlineNavi) {
      var totalID = 0;
      if (
        contentBody.children.length == 0 ||
        (totalID = CreateSectionTreeHelper(contentBody, docOutlineNavi, 0)) == 0
      ) {
        docOutlineNavi.textContent = "　ありません";
      }

      //alert(indexAreaOnSmallScreen);
      if (docOutlineEmbeded) {
        var naviEmbeded = docOutlineNavi.cloneNode(true);
        naviEmbeded.removeAttribute("class");
        naviEmbeded.classList.add("accshow");
        docOutlineEmbeded.appendChild(naviEmbeded);
      }
      //alert(totalID);
      //alert("1");
    }
  }

  var leftSideArea = document.getElementById("left-side-area");
  if (leftSideArea) {
    leftSideArea.querySelectorAll(".selected").forEach(function(value, index) {
      value.scrollIntoView({
        block: "center"
      });
    });
  }

  // var contentSummary = document.getElementById("content-summary");
  // if (contentSummary && contentSummary.textContent.trim() != "") {
  //   // this.console.log(contentSummary.textContent);
  //   contentSummary.style.borderBottomColor = "black";
  // }

  // UpdateCurrentSectionSelection();
  OnScroll();
};

window.onresize = function() {
  if (menuOpenInput && menuOpenInput.checked) {
    CloseLeftSideArea();
  }
};

//
// mainContent内にあるSectionを取得します.
// 同時に, ナヴィゲータの作成, sectionListInMainContent, sectionListInIndexAreaにSectionを登録します.
//
// @param element:
//  Section探索元
//  この下の階層にSectionListが来るようにしてください
//
// @param navi:
//  生成されるナヴィゲータリスト
//
// @param idBegin:
//  振り分け開始id
//
function CreateSectionTreeHelper(element, navi, idBegin) {
  var ulElement = document.createElement("ul");

  for (var i = 0; i < element.children.length; i++) {
    child = element.children[i];

    if (
      child.tagName == "H2" ||
      child.tagName == "H3" ||
      child.tagName == "H4"
    ) {
      child.setAttribute("id", "SectionID_" + idBegin);

      var section = document.createElement("li");
      var link = document.createElement("a");
      // link.innerHTML = child.innerHTML;
      link.textContent = child.textContent;
      link.href = "#SectionID_" + idBegin;
      section.appendChild(link);

      sectionListInSideArea.push(link);

      ulElement.appendChild(section);

      idBegin++;

      if (
        i + 1 < element.children.length &&
        element.children[i + 1].className == "section"
      ) {
        // heading + div(section) per one set.
        sectionListInMainContent.push(child);
        sectionListInMainContent.push(element.children[i + 1]);

        idBegin = CreateSectionTreeHelper(
          element.children[i + 1],
          section,
          idBegin
        );
      } else {
        sectionListInMainContent.push(child);
        sectionListInMainContent.push(null);
      }
    }
  }

  if (ulElement.children.length > 0) {
    navi.appendChild(ulElement);
  }
  return idBegin;
}

sumOfScroll = 0;
isHiddenHeader = false;
function OnScroll() {
  //一定量スクロールされたとき
  if (window.pageYOffset > offsetYToHideHeader) {
    // headerArea.classList.add('transparent');
    // headerArea.style.animationName = "fade-out";
    // headerArea.style.animationDuration = "0.8s";
    if (warningMessageBox != null) {
      warningMessageBox.style.animationName = "warning-message-box-slideout";
      warningMessageBox = null;
    }
  }

  if (sumOfScroll * (window.pageYOffset - scrollPosPrev) < 0.0) {
    sumOfScroll = 0;
  }
  sumOfScroll += window.pageYOffset - scrollPosPrev;
  // scroll_velocity = window.pageYOffset - scrollPosPrev

  if (window.pageYOffset < offsetYToHideHeader) {
    // headerArea.classList.remove('hide-header')
    if (isHiddenHeader) {
      headerArea.style.animationName = "appear-header-anim";
      // menuOpenButton.style.animationName = "slidedown-top-icon";
      // headerArea.style.animationName = "fade-in";
      isHiddenHeader = false;
    }
  } else {
    // headerArea.classList.add('hide-header')
    if (!isHiddenHeader) {
      headerArea.style.animationName = "hide-header-anim";
      // menuOpenButton.style.animationName = "slideup-top-icon";
      // headerArea.style.animationName = "fade-out";
      OnClickPullUpButton();
      isHiddenHeader = true;
    }
  }

  scrollPosPrev = window.pageYOffset;

  if (timer) {
    return;
  }

  timer = setTimeout(function() {
    timer = null;
    UpdateCurrentSectionSelection();
  }, 200);
}

function UpdateCurrentSectionSelection() {
  var selectionUpdated = false;
  var updatedSectionIdDict = {};
  for (var i = 0; i < sectionListInMainContent.length; i++) {
    if (sectionListInMainContent[i] == null) {
      continue;
    }
    var sectionRect = sectionListInMainContent[i].getBoundingClientRect();
    if (
      sectionRect.top < window.innerHeight / 3 &&
      sectionRect.bottom > window.innerHeight / 3
    ) {
      if (!(i in currentSectionIdDict)) {
        selectionUpdated = true;
      }
      updatedSectionIdDict[i] = true;
    }
  }

  // selectionUpdated |= (Object.keys(currentSectionIdDict).length != Object.keys(updatedSectionIdDict).length);
  if (selectionUpdated) {
    for (var id in currentSectionIdDict) {
      sectionListInSideArea[Math.floor(id / 2)].removeAttribute("class");
    }

    for (var id in updatedSectionIdDict) {
      sectionListInSideArea[Math.floor(id / 2)].setAttribute(
        "class",
        "selected"
      );
      sectionListInSideArea[Math.floor(id / 2)].scrollIntoView({
        block: "nearest"
      });
    }
    // alert(docOutlineNavi.scrollTop);
    currentSectionIdDict = updatedSectionIdDict;
  }
}

function IsTouchDevice() {
  var result = false;
  if (window.ontouchstart === null) {
    result = true;
  }
  return result;
}

function OnClickSearchButton(query) {
  searchResultsParent.appendChild(searchResults);
  searchOverlay.classList.add("visible");
  document.body.classList.add("overlay-enabled");
  // document.body.style.overflow = "hidden";
  searchBoxInput.focus();
  if (query) {
    searchBoxInput.value = query;
    OnInputSearchBox(true);
  }
}

function OnClickSearchBoxInputClearButton() {
  searchBoxInput.value = "";
  searchBoxInput.focus();
  OnInputSearchBox(true);
}

function OnClickSearchOverlayCloseButton() {
  searchResultsParent.removeChild(searchResults);
  searchOverlay.classList.remove("visible");
  document.body.classList.remove("overlay-enabled");
  scrollTo(0, 0);
  // document.body.style.overflow = "auto";
}

function OnClickPullDownButton() {
  pullDownMenuButton.style.display = "none";
  pullUpMenuButton.style.display = "block";

  headerArea.classList.add("pull-down");
}

function OnClickPullUpButton() {
  pullDownMenuButton.style.display = "block";
  pullUpMenuButton.style.display = "none";
  headerArea.classList.remove("pull-down");
}

function OnChangeMenuOpen(input) {
  if (input.checked) {
    OpenLeftSideArea();
  } else {
    CloseLeftSideArea();
  }
}

function OpenLeftSideArea() {
  menuOpenInput.checked = true;
  leftSideAreaResponsive.classList.add("left-side-area-responsive-open");

  document.body.style.overflow = "hidden";
  leftSideAreaResponsive.style.zIndex = "99999";
  menuOpenButton.style.zIndex = "99999";
  sitemask.setAttribute("visible", "");
}

function CloseLeftSideArea() {
  menuOpenInput.checked = false;
  leftSideAreaResponsive.classList.remove("left-side-area-responsive-open");

  document.body.style.overflow = "auto";

  leftSideAreaResponsive.style.zIndex = "990";
  menuOpenButton.style.zIndex = "990";
  sitemask.removeAttribute("visible");
}

function OnClickSitemask() {
  CloseLeftSideArea();
}

var searchBoxInputTimer = null;
function OnInputSearchBox(updateResultsImmediately = false) {
  if (searchBoxInputTimer) {
    clearTimeout(searchBoxInputTimer);
  }

  if (searchBoxInput.value) {
    searchBoxInputClearButton.style.display = "block";
  } else {
    searchBoxInputClearButton.style.display = "none";
  }

  if (updateResultsImmediately) {
    UpdateSearchResults();
  } else {
    searchBoxInputTimer = setTimeout(function() {
      searchBoxInputTimer = null;
      UpdateSearchResults();
    }, 1000);
  }
}

function UpdateSearchResults() {
  var form = new FormData();
  form.append("contentPath", contentPath);
  form.append("token", token);
  form.append("query", searchBoxInput.value.replace("　", " "));

  var xhr = new XMLHttpRequest();
  xhr.open("POST", serviceUri + "/contents-search-service.php", true);
  xhr.responseType = "json"; // サーバからのErrorを見たい時は, この行をコメントアウトする

  xhr.onload = function(e) {
    // alert(this.response); // サーバからのErrorを見たい時は, この行をアクティブにする

    if (this.status != 200) {
      return;
    }

    while (searchResults.firstChild)
      searchResults.removeChild(searchResults.firstChild);

    if (this.response.error) {
      // console.log(this.response.error);

      var div = document.createElement("div");
      div.className = "search-results-header";
      div.textContent = this.response.error;
      searchResults.appendChild(div);
      return;
    }

    // console.log(this.response);

    if (this.response.suggestions.length > 0) {
      var ul = document.createElement("ul");
      ul.className = "child-list";

      for (var i = 0; i < this.response.suggestions.length; i++) {
        var suggestion = this.response.suggestions[i];
        var li = document.createElement("li");
        var divWrapper = document.createElement("div");

        var divTitle = document.createElement("div");
        divTitle.className = "child-title";

        var a = document.createElement("a");
        a.href = suggestion.url;
        a.innerHTML =
          suggestion.title +
          (suggestion.parentTitle === false
            ? ""
            : " | " + suggestion.parentTitle);
        divTitle.appendChild(a);

        var divSummary = document.createElement("div");
        divSummary.className = "child-summary";
        divSummary.innerHTML = suggestion.summary;

        divWrapper.appendChild(divTitle);
        divWrapper.appendChild(divSummary);
        li.appendChild(divWrapper);
        ul.appendChild(li);
      }

      searchResults.appendChild(ul);
    } else {
      var div = document.createElement("div");
      div.className = "search-results-header";
      div.textContent = "コンテンツが見つかりませんでした...";
      searchResults.appendChild(div);
    }
  };

  //送信
  xhr.send(form);

  while (searchResults.firstChild)
    searchResults.removeChild(searchResults.firstChild);

  var div = document.createElement("div");
  div.className = "search-results-header";
  div.appendChild(CreateLoader());
  searchResults.appendChild(div);
}

function CreateLoader() {
  var loader = document.createElement("div");
  loader.className = "loader";
  var divWrapper = document.createElement("div");
  // divWrapper.className = 'ball-scale-multiple';
  divWrapper.className = "dot-floating";
  // divWrapper.appendChild(document.createElement('div'));
  // divWrapper.appendChild(document.createElement('div'));
  // divWrapper.appendChild(document.createElement('div'));
  loader.appendChild(divWrapper);
  return loader;
}
// function OpenWindow(url, name) {
// 	win = window.open(url, name);

// 	// return;
// 	// /* ウィンドウオブジェトを格納する変数 */
// 	// var win;
// 	// /* ウィンドウの存在確認をしてからウィンドウを開く */
// 	// if (!win || win.closed) {
// 	// 	/*
// 	// 	ウィンドウオブジェクトを格納した変数が存在しない、
// 	// 	ウィンドウが存在しない、ウィンドウが閉じられている
// 	// 	場合は、新ウィンドウを開く。
// 	// 	*/
// 	// 	win = window.open(url, name);
// 	// } else {
// 	// 	/*
// 	// 	既にウィンドウが開かれている場合は
// 	// 	そのウィンドウにフォーカスを当てる。
// 	// 	*/
// 	// 	win.focus();
// 	// }
// }
