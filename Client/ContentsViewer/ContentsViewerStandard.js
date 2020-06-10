// var offsetYToHideHeader = 100;
var offsetYToHideHeader = 50;

var header = null;
var pullDownMenuButton = null;
var pullUpMenuButton = null;
var leftColumnResponsive = null;
var menuOpenInput = null;
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
var relatedView = null;
var relatedResults = null;
var relatedViewObserver = null;
var token = "";
var contentPath = "";
var serviceUri = "";
var contentBody;
var pullDownMenu;

var sectionListInMainContent = [];
var sectionListInColumn = [];
var currentSectionIdDict = {};

var timer = null;
var scrollPosPrev = 0;

document.addEventListener("DOMContentLoaded", function() {
  // 各Area取得
  header = document.querySelector("#header");
  pullDownMenuButton = document.querySelector("#pull-down-menu-button");
  pullUpMenuButton = document.querySelector("#pull-up-menu-button");
  warningMessageBox = document.getElementById("warning-message-box");
  leftColumnResponsive = document.getElementById("left-column-responsive");
  menuOpenInput = document.getElementById("menu-open");
  sitemask = document.getElementById("sitemask");
  menuOpenButton = document.getElementsByClassName(
    "menu-open-button-wrapper"
  )[0];
  contentBody = document.getElementById("content-body");
  pullDownMenu = document.getElementById("pull-down-menu");
  searchOverlay = document.getElementById("search-overlay");
  searchResults = document.getElementById("search-results");
  searchBoxInput = document.getElementById("search-box-input");
  searchBoxInputClearButton = document.getElementById(
    "search-box-input-clear-button"
  );
  relatedView = document.getElementById("related-view");
  relatedResults = document.getElementById("related-results");
  token = document.getElementById("token").value;
  contentPath = document.getElementById("contentPath").value;
  serviceUri = document.getElementById("serviceUri").value;

  searchResultsParent = searchResults.parentNode;
  searchResultsParent.removeChild(searchResults);
  searchBoxInputClearButton.style.display = "none";

  // document.querySelectorAll('#content-summary a.link, #content-body a.link').forEach(link => OpenExternalLinksInNewWindow(link));

  if (relatedResults) {
    relatedViewObserver = new IntersectionObserver(function(entries) {
      // If intersectionRatio is 0, the target is out of view
      // and we do not need to do anything.
      if (entries[0].intersectionRatio <= 0) return;
      GetRelatedContents();
      relatedViewObserver.disconnect();
    });
    relatedViewObserver.observe(relatedResults);
  }

  scrollPosPrev = window.pageYOffset;

  // Scrollイベント登録
  window.addEventListener("scroll", OnScroll);

  // タッチデバイス判定
  isTouchDevice = IsTouchDevice();

  // --- 目次関係 --------------------------------------------
  var rightColumn = document.getElementById("right-column");
  var docOutlineEmbeded = document.getElementById("doc-outline-embeded");

  if (contentBody && rightColumn) {
    // rightColumn 内にあるNaviを取得
    if (rightColumn.getElementsByClassName("navi").length > 0) {
      docOutlineNavi = rightColumn.getElementsByClassName("navi")[0];
    }

    // Naviを取得できた場合のみ実行
    if (docOutlineNavi) {
      var totalID = 0;
      if (
        contentBody.children.length != 0 &&
        (totalID = CreateSectionTreeHelper(contentBody, docOutlineNavi, 0)) != 0
      ) {
        docOutlineNavi.removeChild(docOutlineNavi.firstChild);
      }

      if (docOutlineEmbeded) {
        var naviEmbeded = docOutlineNavi.cloneNode(true);
        naviEmbeded.removeAttribute("class");
        naviEmbeded.classList.add("accshow");
        docOutlineEmbeded.appendChild(naviEmbeded);

        var toggleDocOutline = document.getElementById("toggle-doc-outline");
        naviEmbeded.setAttribute("aria-hidden", "true");
        toggleDocOutline.addEventListener("change", function() {
          if (this.checked) {
            naviEmbeded.setAttribute("aria-hidden", "false");
          } else {
            naviEmbeded.setAttribute("aria-hidden", "true");
          }
        });
      }
    }
  }

  var leftColumn = document.getElementById("left-column");
  if (leftColumn) {
    leftColumn.querySelectorAll(".selected").forEach(function(value, index) {
      value.scrollIntoView({
        block: "center"
      });
    });
  }

  if (leftColumnResponsive) {
    leftColumnResponsive
      .querySelectorAll(".selected")
      .forEach(function(value, index) {
        value.scrollIntoView({
          block: "center"
        });
      });
  }

  var sectionHeadings = document.querySelectorAll("#content-body h2");
  // console.log(sectionHeadings);
  // var expanded = window.innerWidth > 700;
  var expanded = true;

  for (var i = 0; i < sectionHeadings.length; i++) {
    var heading = sectionHeadings[i];
    var controlId = "content-collapsible-block-" + i;

    heading.setAttribute("aria-haspopup", "true");
    heading.setAttribute("aria-controls", controlId);
    heading.setAttribute("tabindex", "0");
    heading.setAttribute("aria-expanded", expanded);

    if (!expanded) heading.classList.add("close-block");

    heading.addEventListener("click", function(event) {
      var expanded = false;
      if (this.classList.contains("close-block")) {
        ToggleBlockExpanded(this, true);
      } else {
        ToggleBlockExpanded(this, false);
      }
    });

    heading.addEventListener("keypress", function(event) {
      // スペースかエンターが押されているかを確認
      if (event.key === " " || event.key === "Enter") {
        // スペースが押されたときにスクロールさせないためにデフォルトの振る舞いをキャンセル
        event.preventDefault();
        var expanded = false;
        if (this.classList.contains("close-block")) {
          ToggleBlockExpanded(this, true);
        } else {
          ToggleBlockExpanded(this, false);
        }
      }
    });

    if (
      heading.nextSibling &&
      heading.nextSibling.classList.contains("section")
    ) {
      var section = heading.nextSibling;
      section.id = controlId;
      section.setAttribute("aria-hidden", !expanded);
    }
  }

  window.addEventListener(
    "hashchange",
    function(event) {
      // this.alert(window.location.hash);
      if (JumpToHash(window.location.hash)) {
        event.preventDefault();
      }
    },
    false
  );

  // UpdateCurrentSectionSelection();
  JumpToHash(window.location.hash);
  OnScroll();
});

window.onresize = function() {
  if (menuOpenInput && menuOpenInput.checked) {
    CloseLeftColumn();
  }
};

function JumpToHash(hash) {
  if (!hash) return false;
  hash = hash.substr(1);
  hash = decodeURIComponent(hash);
  // alert(hash);
  var target = document.getElementById(hash);
  if (!target) target = document.querySelector('[name="' + hash + '"]');
  // console.log(target);
  // alert(typeof target);
  if (target) {
    var iterator = target;
    while (iterator) {
      // this.alert(iterator);
      if (
        !iterator ||
        iterator === contentBody ||
        iterator === this.document.body
      ) {
        return false;
      }

      if (iterator.hasAttribute("aria-hidden")) {
        var controller = this.document.querySelector(
          '[aria-controls="' + iterator.id + '"]'
        );
        ToggleBlockExpanded(controller, true);
        GetScrollElement().scrollTop = GetElementOffset(target).top;
        // alert("O");
        return true;
      }

      if (iterator.hasAttribute("aria-haspopup")) {
        ToggleBlockExpanded(iterator, true);
        GetScrollElement().scrollTop = GetElementOffset(target).top;
        // alert("K");
        return true;
      }

      iterator = iterator.parentElement;
    }
  }
}

function ToggleBlockExpanded(controller, expanded) {
  if (expanded) {
    controller.classList.remove("close-block");
  } else {
    controller.classList.add("close-block");
  }
  controller.setAttribute("aria-expanded", expanded);
  var controlId = controller.getAttribute("aria-controls");
  var control = document.getElementById(controlId);

  control.setAttribute("aria-hidden", !expanded);
}

function GetElementOffset(element) {
  var rect = element.getBoundingClientRect();
  // alert(rect.top);
  // alert(window.pageYOffset || document.documentElement.scrollTop);
  // alert(rect.top + (window.pageYOffset || document.documentElement.scrollTop));
  return {
    top: rect.top + (window.pageYOffset || document.documentElement.scrollTop),
    left:
      rect.left + (window.pageXOffset || document.documentElement.scrollLeft)
  };
}

function GetScrollElement() {
  if ("scrollingElement" in document) {
    return document.scrollingElement;
  }
  if (navigator.userAgent.indexOf("WebKit") != -1) {
    return document.body;
  }
  return document.documentElement;
}
//
// mainContent内にあるSectionを取得します.
// 同時に, ナヴィゲータの作成, sectionListInMainContent にSectionを登録します.
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

      sectionListInColumn.push(link);

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

var sumOfScroll = 0;
var isHiddenHeader = false;
function OnScroll() {
  sumOfScroll += window.pageYOffset - scrollPosPrev;
  if (Math.abs(sumOfScroll) > offsetYToHideHeader) {
    //一定量スクロールされたとき
    CloseWarningMessageBox();
  }

  if (window.pageYOffset < offsetYToHideHeader) {
    if (isHiddenHeader) {
      header.style.animationName = "appear-header-anim";
      isHiddenHeader = false;
    }
  } else {
    if (!isHiddenHeader) {
      header.style.animationName = "hide-header-anim";
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
      sectionListInColumn[Math.floor(id / 2)].removeAttribute("class");
    }

    for (var id in updatedSectionIdDict) {
      sectionListInColumn[Math.floor(id / 2)].setAttribute("class", "selected");
      sectionListInColumn[Math.floor(id / 2)].scrollIntoView({
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

function OnClickThemeChangeButton() {
  var currentTheme = ThemeChanger.getCurrentTheme();
  if (currentTheme === "dark") {
    ThemeChanger.clearTheme();
  } else {
    ThemeChanger.setTheme("dark");
  }
  // alert("A");
  ThemeChanger.changeTheme();
}

function OnClickPullDownButton() {
  pullDownMenuButton.style.display = "none";
  pullUpMenuButton.style.display = "block";

  pullDownMenu.setAttribute("aria-hidden", "false");

  header.classList.add("pull-down");
}

function OnClickPullUpButton() {
  pullDownMenuButton.style.display = "block";
  pullUpMenuButton.style.display = "none";

  pullDownMenu.setAttribute("aria-hidden", "true");

  header.classList.remove("pull-down");
}

function OnChangeMenuOpen(input) {
  if (input.checked) {
    OpenLeftColumn();
  } else {
    CloseLeftColumn();
  }
}

function OpenLeftColumn() {
  menuOpenInput.checked = true;
  leftColumnResponsive.classList.add("left-column-responsive-open");

  document.body.style.overflow = "hidden";
  leftColumnResponsive.style.zIndex = "99999";
  menuOpenButton.style.zIndex = "99999";
  sitemask.setAttribute("visible", "");
}

function CloseLeftColumn() {
  menuOpenInput.checked = false;
  leftColumnResponsive.classList.remove("left-column-responsive-open");

  document.body.style.overflow = "auto";

  leftColumnResponsive.style.zIndex = "990";
  menuOpenButton.style.zIndex = "990";
  sitemask.removeAttribute("visible");
}

function OnClickSitemask() {
  CloseLeftColumn();
}

function CloseWarningMessageBox() {
  if (warningMessageBox != null) {
    warningMessageBox.style.animationName = "warning-message-box-slideout";
    warningMessageBox = null;
  }
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
  xhr.onload = function (e) {
    RemoveChildElements(searchResults);
  
    try {
      if (!ValidateResponse(this)) {
        throw "Sorry... Internal Error occured.";
      }

      if (this.parsedResponse.error) {
        throw this.parsedResponse.error;
      }
    }
    catch (err) {
      var div = document.createElement("div");
      div.className = "search-results-header";
      div.textContent = err
      searchResults.appendChild(div);
      return;
    }

    if (this.parsedResponse.suggestions.length > 0) {
      var ul = document.createElement("ul");
      ul.className = "child-list";

      for (var i = 0; i < this.parsedResponse.suggestions.length; i++) {
        var suggestion = this.parsedResponse.suggestions[i];
        var li = document.createElement("li");
        var divWrapper = document.createElement("div");

        var divTitle = document.createElement("div");
        divTitle.className = "child-title";

        var a = document.createElement("a");
        a.href = suggestion.url;
        a.innerHTML =
          NotBlankText([suggestion.title, suggestion.url.split('/').pop()]) +
          (suggestion.parentTitle === false
            ? ""
            : " | " + NotBlankText([suggestion.parentTitle, suggestion.parentUrl.split('/').pop()]));
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
      div.textContent = "Not Found...";
      searchResults.appendChild(div);
    }
  };

  //送信
  xhr.send(form);

  RemoveChildElements(searchResults);

  var div = document.createElement("div");
  div.className = "search-results-header";
  div.appendChild(CreateLoader());
  searchResults.appendChild(div);
}

function GetRelatedContents() {
  var form = new FormData();
  form.append("contentPath", contentPath);
  form.append("token", token);
  
  var xhr = new XMLHttpRequest();
  xhr.open("POST", serviceUri + "/related-search-service.php", true);
  xhr.onload = function (e) {
    RemoveChildElements(relatedResults);
    try {
      if (!ValidateResponse(this)) {
        throw "Sorry... Internal Error occured.";
      }

      if (this.parsedResponse.error) {
        throw this.parsedResponse.error;
      }
    }
    catch (err) {
      var div = document.createElement("div");
      div.className = "related-results-header";
      div.textContent = err;
      relatedResults.appendChild(div);
      return;
    }

    var related = this.parsedResponse.related;
    if (related.length > 0) {
      related.forEach((each) => {
        var wrapper = document.createElement('div');
        wrapper.className = 'card-wrapper';
        
        var head = document.createElement('a');
        head.className = 'card-item head';
        var inner = document.createElement('div');
        inner.className = 'inner';
        var title = document.createElement('div');
        title.className = 'title';
        title.textContent = each.keyword;
        inner.appendChild(title);

        if (each.detailURL !== false) {
          head.href = each.detailURL;
        }
        
        if (each.type == 'tag') {
          head.classList.add('tag');
          var icon = document.createElement('div');
          icon.className = 'tag-icon icon'
          inner.appendChild(icon);
        }
        else if (each.type == 'link') {
          head.classList.add('link');
          var icon = document.createElement('div');
          icon.className = 'link-icon icon'
          inner.appendChild(icon);
        }
        head.appendChild(inner);
        wrapper.appendChild(head);

        each.contents.forEach((content) => {
          var item = document.createElement('div');
          item.className = 'card-item';
          var hoverLink = document.createElement('a');
          hoverLink.className = 'hover-link';
          hoverLink.href = content.url;
          var inner = document.createElement('div');
          inner.className = 'inner';
          var title = document.createElement('a');
          title.href = content.url;
          title.className = 'title';
          title.textContent = NotBlankText([content.title, content.url.split('/').pop()]) +(content.parentTitle === false ? "" : " | " + NotBlankText([content.parentTitle, content.parentURL.split('/').pop()]));
          inner.appendChild(title);
          var summary = document.createElement('div');
          summary.innerHTML = content.summary;
          summary.className = 'summary';
          inner.appendChild(summary);
          item.appendChild(inner);
          item.appendChild(hoverLink);
          wrapper.appendChild(item);
        })
        relatedResults.appendChild(wrapper);

        var splitter = document.createElement('div');
        splitter.className = 'splitter';
        relatedResults.appendChild(splitter);
      })
    }
    else {
      var div = document.createElement("div");
      div.className = "related-results-header";
      div.textContent = "Not Found...";
      relatedResults.appendChild(div);
    }
    // console.log(this.parsedResponse);
  }
  
  // window.setTimeout(function () { xhr.send(form); }, 1000 ); // delay 1 sec
  xhr.send(form);
  RemoveChildElements(relatedResults)
  var div = document.createElement("div");
  div.className = "related-results-header";
  div.appendChild(CreateLoader());
  relatedResults.appendChild(div);
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

function CreateLoader() {
  var loader = document.createElement("div");
  loader.className = "loader dot-floating";
  loader.appendChild(document.createElement("div"));
  loader.appendChild(document.createElement("div"));
  loader.appendChild(document.createElement("div"));
  return loader;
}

function NotBlankText(texts) {
  for (var i = 0; i < texts.length; i++){
    if (texts[i] != '') {
      return texts[i];
    }
  }

  return texts[texts.length - 1];
}

function OnClickLayerSelector(element, event) {
  
  if (element.parentNode.hasAttribute('open')) {
    return;
  }

  var closeClickHandler = function () {
    element.parentNode.removeAttribute('open');
  }

  event.stopPropagation();
  element.parentNode.setAttribute('open', '');
  document.addEventListener('click', closeClickHandler, {once: true, capture: false});
}

function RemoveChildElements(parent) {
  while (parent.firstChild) parent.removeChild(parent.firstChild);
}

function OpenExternalLinksInNewWindow(link) {
  if (link.href.indexOf(location.protocol + '//' + location.hostname) != 0) {
    link.target = '_blank';
  }
}