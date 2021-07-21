
// Create namespace.
// refs:
//  <https://flaviocopes.com/javascript-namespaces/>
const ContentsViewer = {}

ContentsViewer.config = {
  offsetYToHideHeader: 50
}

ContentsViewer.elements = {}

ContentsViewer.vars = {}

ContentsViewer.private = {}


ContentsViewer.private.initOnHead = () => {
  var cv = ContentsViewer;

  // set variables.
  cv.vars.token = document.getElementsByName("token").item(0)?.content;
  cv.vars.contentPath = document.getElementsByName("content-path").item(0)?.content;
  cv.vars.serviceUri = document.getElementsByName("service-uri").item(0)?.content;
  cv.vars.otp = document.getElementsByName('otp').item(0)?.content;
}

ContentsViewer.private.initOnDOMLoaded = () => {
  var cv = ContentsViewer;

  // get elements.
  cv.elements.header = document.querySelector("#header");
  cv.elements.pullDownMenuButton = document.querySelector("#pull-down-menu-button");
  cv.elements.pullUpMenuButton = document.querySelector("#pull-up-menu-button");
  cv.elements.warningMessageBox = document.getElementById("warning-message-box");
  cv.elements.leftColumn = document.getElementById("left-column");
  cv.elements.leftColumnResponsive = document.getElementById("left-column-responsive");
  cv.elements.menuOpenInput = document.getElementById("menu-open");
  cv.elements.sitemask = document.getElementById("sitemask");
  cv.elements.menuOpenButton = document.getElementsByClassName("menu-open-button-wrapper")[0];
  cv.elements.contentBody = document.getElementById("content-body");
  cv.elements.pullDownMenu = document.getElementById("pull-down-menu");
  cv.elements.searchOverlay = document.getElementById("search-overlay");
  cv.elements.searchResults = document.getElementById("search-results");
  cv.elements.searchResultsParent = cv.elements.searchResults.parentNode;
  cv.elements.searchBoxInput = document.getElementById("search-box-input");
  cv.elements.searchBoxInputClearButton = document.getElementById("search-box-input-clear-button");
  cv.elements.relatedView = document.getElementById("related-view");
  cv.elements.relatedResults = document.getElementById("related-results");
  cv.elements.rightColumn = document.getElementById("right-column");
  cv.elements.docOutlineEmbeded = document.getElementById("doc-outline-embeded");


  cv.private.setupSearching();
  cv.private.setupRelatedView();
  cv.private.setupOutline();
  cv.private.setupLeftColumn();
  cv.private.setupSectionHeadings();


  // init hash-changing processings.
  window.addEventListener(
    "hashchange",
    function (event) {
      if (cv.jumpToHash(window.location.hash)) {
        event.preventDefault();
      }
    },
    false
  );
  cv.jumpToHash(window.location.hash);

  window.addEventListener("resize", function () {
    if (cv.elements.menuOpenInput && cv.elements.menuOpenInput.checked) {
      cv.closeLeftColumn();
    }
  })

  // init scroll processings.
  window.addEventListener("scroll", cv.private.onScroll());
  cv.private.onScroll();
}

ContentsViewer.private.onScroll = () => {
  var cv = ContentsViewer;
  var sumOfScroll = 0;
  var isHiddenHeader = false;
  var timer = null;
  var scrollPosPrev = window.pageYOffset;

  return () => {
    sumOfScroll += window.pageYOffset - scrollPosPrev;
    if (Math.abs(sumOfScroll) > cv.config.offsetYToHideHeader) {
      cv.closeWarningMessageBox();
    }

    if (window.pageYOffset < cv.config.offsetYToHideHeader) {
      if (isHiddenHeader) {
        cv.elements.header.style.animationName = "appear-header-anim";
        isHiddenHeader = false;
      }
    } else {
      if (!isHiddenHeader) {
        cv.elements.header.style.animationName = "hide-header-anim";
        cv.onClickPullUpButton();
        isHiddenHeader = true;
      }
    }

    scrollPosPrev = window.pageYOffset;

    if (timer) {
      return;
    }

    timer = setTimeout(function () {
      timer = null;

      cv.private.updateCurrentSectionSelection(
        cv.private.sectionListInColumn,
        cv.private.sectionListInMainContent
      );
    }, 200);
  }
}

ContentsViewer.private.setupOutline = () => {
  var cv = ContentsViewer;

  cv.private.sectionListInColumn = [];
  cv.private.sectionListInMainContent = [];

  // Need contentBody and rightColumn.
  if (!cv.elements.contentBody || !cv.elements.rightColumn) {
    return;
  }

  var docOutlineNavi = cv.elements.rightColumn.getElementsByClassName("navi")?.[0];
  if (!docOutlineNavi) {
    return;
  }

  var naviEmbeded = docOutlineNavi.cloneNode(true);
  naviEmbeded.removeAttribute("class");
  var navWrapper = document.querySelector("#doc-outline-embeded>.nav-wrapper");
  navWrapper.appendChild(naviEmbeded);

  if (cv.elements.contentBody.children.length != 0) {
    if ((cv.private.createSectionTreeHelper(
      cv.elements.contentBody, docOutlineNavi, 0,
      cv.private.sectionListInColumn,
      cv.private.sectionListInMainContent)) != 0) {
      docOutlineNavi.removeChild(docOutlineNavi.firstChild);
    }

    if ((cv.private.createSectionTreeHelper(
      cv.elements.contentBody, naviEmbeded, 0,
      [], [])) != 0) {
      naviEmbeded.removeChild(naviEmbeded.firstChild);
    }
  }

  var listItems = naviEmbeded.getElementsByTagName("li");
  var maxVisibleCount = 5;
  for (var i = 0, loop = Math.min(maxVisibleCount, listItems.length); i < loop; i++) {
    listItems[i].setAttribute("visible", "");
  }
  if (listItems.length <= maxVisibleCount) {
    var toggleDocOutlineLabel = document.querySelector("#doc-outline-embeded>label");
    toggleDocOutlineLabel.style.display = "none";
  }
}

ContentsViewer.private.setupLeftColumn = () => {
  var cv = ContentsViewer;

  if (cv.elements.leftColumn) {
    cv.elements.leftColumn.querySelectorAll(".selected").forEach(function (value, index) {
      value.scrollIntoView({
        block: "center"
      });
    });
  }
  if (cv.elements.leftColumnResponsive) {
    cv.elements.leftColumnResponsive.querySelectorAll(".selected").forEach(function (value, index) {
      value.scrollIntoView({
        block: "center"
      });
    });
  }
}

ContentsViewer.private.setupSectionHeadings = () => {
  var cv = ContentsViewer;
  var sectionHeadings = document.querySelectorAll("#content-body h2");
  var expanded = true;

  for (var i = 0; i < sectionHeadings.length; i++) {
    var heading = sectionHeadings[i];
    var controlId = "content-collapsible-block-" + i;

    heading.setAttribute("aria-haspopup", "true");
    heading.setAttribute("aria-controls", controlId);
    heading.setAttribute("tabindex", "0");
    heading.setAttribute("aria-expanded", expanded);

    if (!expanded) heading.classList.add("close-block");

    heading.addEventListener("click", function (event) {
      var expanded = false;
      if (this.classList.contains("close-block")) {
        cv.toggleBlockExpanded(this, true);
      } else {
        cv.toggleBlockExpanded(this, false);
      }
    });

    heading.addEventListener("keypress", function (event) {
      // スペースかエンターが押されているかを確認
      if (event.key === " " || event.key === "Enter") {
        // スペースが押されたときにスクロールさせないためにデフォルトの振る舞いをキャンセル
        event.preventDefault();
        var expanded = false;
        if (this.classList.contains("close-block")) {
          cv.toggleBlockExpanded(this, true);
        } else {
          cv.toggleBlockExpanded(this, false);
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
}

ContentsViewer.private.setupRelatedView = () => {
  var cv = ContentsViewer;
  if (cv.elements.relatedResults) {
    cv.private.relatedViewObserver = new IntersectionObserver((entries) => {
      // If intersectionRatio is 0, the target is out of view
      // and we do not need to do anything.
      if (entries[0].intersectionRatio <= 0) return;
      cv.getRelatedContents();
      cv.private.relatedViewObserver.disconnect();
    });
    cv.private.relatedViewObserver.observe(cv.elements.relatedResults);
  }
}

ContentsViewer.private.setupSearching = () => {
  var cv = ContentsViewer;
  cv.elements.searchResultsParent.removeChild(cv.elements.searchResults);
  cv.elements.searchBoxInputClearButton.style.display = "none";
}

ContentsViewer.jumpToHash = (hash) => {
  var cv = ContentsViewer;

  if (!hash) return false;
  hash = hash.substr(1);
  hash = decodeURIComponent(hash);
  var target = document.getElementById(hash);
  if (!target) target = document.querySelector('[name="' + hash + '"]');
  if (target) {
    var iterator = target;
    while (iterator) {
      if (
        !iterator ||
        iterator === cv.elements.contentBody ||
        iterator === this.document.body
      ) {
        return false;
      }

      if (iterator.hasAttribute("aria-hidden")) {
        var controller = this.document.querySelector(
          '[aria-controls="' + iterator.id + '"]'
        );
        cv.toggleBlockExpanded(controller, true);
        cv.getScrollElement().scrollTop = cv.getElementOffset(target).top;
        // alert("O");
        return true;
      }

      if (iterator.hasAttribute("aria-haspopup")) {
        cv.toggleBlockExpanded(iterator, true);
        cv.getScrollElement().scrollTop = cv.getElementOffset(target).top;
        return true;
      }

      iterator = iterator.parentElement;
    }
  }
}

ContentsViewer.toggleBlockExpanded = (controller, expanded) => {
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

ContentsViewer.getScrollElement = () => {
  if ("scrollingElement" in document) {
    return document.scrollingElement;
  }
  if (navigator.userAgent.indexOf("WebKit") != -1) {
    return document.body;
  }
  return document.documentElement;
}

ContentsViewer.getElementOffset = (element) => {
  var rect = element.getBoundingClientRect();
  return {
    top:
      rect.top + (window.pageYOffset || document.documentElement.scrollTop),
    left:
      rect.left + (window.pageXOffset || document.documentElement.scrollLeft)
  };
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
ContentsViewer.private.createSectionTreeHelper = (
  element, navi, idBegin,
  sectionListInColumn, sectionListInMainContent) => {
  var cv = ContentsViewer;

  var ulElement = document.createElement("ul");

  for (var i = 0; i < element.children.length; i++) {
    var child = element.children[i];

    if (
      child.tagName == "H2" ||
      child.tagName == "H3" ||
      child.tagName == "H4"
    ) {
      child.setAttribute("id", "SectionID_" + idBegin);

      var section = document.createElement("li");
      var link = document.createElement("a");
      link.textContent = cv.private.getVisibleText(child).replace(/\$/g, "\\\$");
      link.href = "#SectionID_" + idBegin;
      section.appendChild(link);
      ulElement.appendChild(section);

      (function (target, link) {
        var observer = new MutationObserver((mutations) => {
          link.textContent = cv.private.getVisibleText(target).replace(/\$/g, "\\\$");
        })
        observer.observe(target, { characterData: true, subtree: true });
      })(child, link);

      sectionListInColumn.push(link);
      idBegin++;

      if (
        i + 1 < element.children.length &&
        element.children[i + 1].className == "section"
      ) {
        // heading + div(section) per one set.
        sectionListInMainContent.push(child);
        sectionListInMainContent.push(element.children[i + 1]);

        idBegin = cv.private.createSectionTreeHelper(
          element.children[i + 1],
          section,
          idBegin,
          sectionListInColumn,
          sectionListInMainContent
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

ContentsViewer.private.getVisibleText = (element) => {
  var text = "";
  (function walk(node) {
    if (node.nodeType == Node.ELEMENT_NODE) {
      var style = window.getComputedStyle(node);
      if (style.display == "none"
        || style.visibility == "hidden") {
        return;
      }
    }

    if (node.childNodes.length == 0) {
      text += node.textContent;
    }

    for (var i = 0; i < node.childNodes.length; ++i) {
      walk(node.childNodes[i]);
    }
  })(element);
  return text;
}

ContentsViewer.private.updateCurrentSectionSelection = (() => {
  var currents = {}

  return (sectionListInColumn, sectionListInMainContent) => {
    var nexts = {}
    var updated = false;

    for (var i = 0; i < sectionListInMainContent.length; i += 2) {
      var heading = sectionListInMainContent[i];
      var section = sectionListInMainContent[i + 1];
      var top, bottom;
      if (heading) {
        var rect = heading.getBoundingClientRect();
        top = rect.top;
        bottom = rect.bottom;
      }
      if (section) {
        var rect = section.getBoundingClientRect();
        bottom = rect.bottom;
      }

      if (top < window.innerHeight / 3
        && window.innerHeight / 3 < bottom) {
        if (!(i in currents)) {
          updated = true;
        }
        nexts[i] = true;
      }
    }

    if (updated) {
      for (var i in currents) {
        sectionListInColumn[Math.floor(i / 2)].classList.remove("selected");
      }

      for (var i in nexts) {
        sectionListInColumn[Math.floor(i / 2)].classList.add("selected");
        sectionListInColumn[Math.floor(i / 2)].scrollIntoView({
          block: "nearest"
        });
      }
      currents = nexts;
    }
  };
})();

ContentsViewer.onClickPullUpButton = () => {
  var cv = ContentsViewer;
  cv.elements.pullDownMenuButton.style.display = "block";
  cv.elements.pullUpMenuButton.style.display = "none";
  cv.elements.pullDownMenu.setAttribute("aria-hidden", "true");
  cv.elements.header.classList.remove("pull-down");
}

ContentsViewer.onClickPullDownButton = (event) => {
  var cv = ContentsViewer;

  event.stopPropagation();

  cv.elements.pullDownMenuButton.style.display = "none";
  cv.elements.pullUpMenuButton.style.display = "block";
  cv.elements.pullDownMenu.setAttribute("aria-hidden", "false");
  cv.elements.header.classList.add("pull-down");

  var onClickOuterOfHeader = function (e) {
    e.stopPropagation();
    var element = e.target;
    while (element) {
      if (element === cv.elements.header
        || element === cv.elements.searchOverlay) {
        document.addEventListener('click', onClickOuterOfHeader, { once: true, capture: false });
        return;
      }
      element = element.parentNode;
    }
    cv.onClickPullUpButton();
  }
  document.addEventListener('click', onClickOuterOfHeader, { once: true, capture: false });
}

ContentsViewer.closeWarningMessageBox = () => {
  var cv = ContentsViewer;
  if (cv.elements.warningMessageBox != null) {
    cv.elements.warningMessageBox.style.animationName = "warning-message-box-slideout";
    cv.elements.warningMessageBox = null;
  }
}

ContentsViewer.onClickSearchButton = (query) => {
  var cv = ContentsViewer;
  cv.elements.searchResultsParent.appendChild(cv.elements.searchResults);
  cv.elements.searchOverlay.classList.add("visible");
  document.body.classList.add("overlay-enabled");
  cv.elements.searchBoxInput.focus();
  if (query) {
    cv.elements.searchBoxInput.value = query;
    cv.onInputSearchBox(true);
  }
}

ContentsViewer.onClickSearchBoxInputClearButton = () => {
  var cv = ContentsViewer;
  cv.elements.searchBoxInput.value = "";
  cv.elements.searchBoxInput.focus();
  cv.onInputSearchBox(true);
}

ContentsViewer.onClickSearchOverlayCloseButton = () => {
  var cv = ContentsViewer;
  cv.elements.searchResultsParent.removeChild(cv.elements.searchResults);
  cv.elements.searchOverlay.classList.remove("visible");
  document.body.classList.remove("overlay-enabled");
  scrollTo(0, 0);
}

ContentsViewer.onClickThemeChangeButton = () => {
  var currentTheme = ThemeChanger.getCurrentTheme();
  if (currentTheme === "dark") {
    ThemeChanger.clearTheme();
  } else {
    ThemeChanger.setTheme("dark");
  }
  ThemeChanger.changeTheme();
}

ContentsViewer.onChangeMenuOpen = (input) => {
  var cv = ContentsViewer;
  if (input.checked) { cv.openLeftColumn(); }
  else { cv.closeLeftColumn(); }
}

ContentsViewer.openLeftColumn = () => {
  var cv = ContentsViewer;
  cv.elements.menuOpenInput.checked = true;
  cv.elements.leftColumnResponsive.classList.add("left-column-responsive-open");

  document.body.style.overflow = "hidden";
  cv.elements.leftColumnResponsive.style.zIndex = "99999";
  cv.elements.menuOpenButton.style.zIndex = "99999";
  cv.elements.sitemask.setAttribute("visible", "");
}

ContentsViewer.closeLeftColumn = () => {
  var cv = ContentsViewer;
  cv.elements.menuOpenInput.checked = false;
  cv.elements.leftColumnResponsive.classList.remove("left-column-responsive-open");

  document.body.style.overflow = "auto";

  cv.elements.leftColumnResponsive.style.zIndex = "990";
  cv.elements.menuOpenButton.style.zIndex = "990";
  cv.elements.sitemask.removeAttribute("visible");
}

ContentsViewer.onClickSitemask = () => {
  var cv = ContentsViewer;
  cv.closeLeftColumn();
}

ContentsViewer.removeChildElements = (parent) => {
  while (parent.firstChild) parent.removeChild(parent.firstChild);
}

ContentsViewer.getRelatedContents = () => {
  var cv = ContentsViewer;
  var form = new FormData();
  form.append("contentPath", cv.vars.contentPath);
  form.append("token", cv.vars.token);

  var xhr = new XMLHttpRequest();
  xhr.open("POST", cv.vars.serviceUri + "/related-search-service.php", true);
  xhr.onload = function (e) {
    cv.removeChildElements(cv.elements.relatedResults);
    try {
      if (!cv.validateResponse(this)) {
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
      cv.elements.relatedResults.appendChild(div);
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
          title.textContent = cv.notBlankText(
            [content.title, content.url.split('/').pop()]) + (content.parentTitle === false
              ? ""
              : " | " + cv.notBlankText([content.parentTitle, content.parentURL.split('/').pop()]));
          inner.appendChild(title);
          var summary = document.createElement('div');
          summary.innerHTML = content.summary;
          summary.className = 'summary';
          inner.appendChild(summary);
          item.appendChild(inner);
          item.appendChild(hoverLink);
          wrapper.appendChild(item);
        })
        cv.elements.relatedResults.appendChild(wrapper);

        var splitter = document.createElement('div');
        splitter.className = 'splitter';
        cv.elements.relatedResults.appendChild(splitter);
      })
    }
    else {
      var div = document.createElement("div");
      div.className = "related-results-header";
      div.textContent = "Not Found...";
      cv.elements.relatedResults.appendChild(div);
    }
    // console.log(this.parsedResponse);
  }

  // window.setTimeout(function () { xhr.send(form); }, 1000 ); // delay 1 sec
  xhr.send(form);
  cv.removeChildElements(cv.elements.relatedResults)
  var div = document.createElement("div");
  div.className = "related-results-header";
  div.appendChild(cv.createLoader());
  cv.elements.relatedResults.appendChild(div);
}

ContentsViewer.onInputSearchBox = (updateResultsImmediately = false) => {
  var cv = ContentsViewer;

  if (cv.private.searchBoxInputTimer) {
    clearTimeout(cv.private.searchBoxInputTimer);
  }

  if (cv.elements.searchBoxInput.value) {
    cv.elements.searchBoxInputClearButton.style.display = "block";
  } else {
    cv.elements.searchBoxInputClearButton.style.display = "none";
  }

  if (updateResultsImmediately) {
    cv.updateSearchResults();
  } else {
    cv.private.searchBoxInputTimer = setTimeout(function () {
      cv.private.searchBoxInputTimer = null;
      cv.updateSearchResults();
    }, 1000);
  }
}

ContentsViewer.updateSearchResults = () => {
  var cv = ContentsViewer;

  var form = new FormData();
  form.append("contentPath", cv.vars.contentPath);
  form.append("token", cv.vars.token);
  form.append("query", cv.elements.searchBoxInput.value.replace("　", " "));

  var xhr = new XMLHttpRequest();
  xhr.open("POST", cv.vars.serviceUri + "/contents-search-service.php", true);
  xhr.onload = function (e) {
    cv.removeChildElements(cv.elements.searchResults);

    try {
      if (!cv.validateResponse(this)) {
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
      cv.elements.searchResults.appendChild(div);
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
          cv.notBlankText([suggestion.title, suggestion.url.split('/').pop()]) +
          (suggestion.parentTitle === false
            ? ""
            : " | " + cv.notBlankText([suggestion.parentTitle, suggestion.parentUrl.split('/').pop()]));
        divTitle.appendChild(a);

        var divSummary = document.createElement("div");
        divSummary.className = "child-summary";
        divSummary.innerHTML = suggestion.summary;

        divWrapper.appendChild(divTitle);
        divWrapper.appendChild(divSummary);
        li.appendChild(divWrapper);
        ul.appendChild(li);
      }

      cv.elements.searchResults.appendChild(ul);
    } else {
      var div = document.createElement("div");
      div.className = "search-results-header";
      div.textContent = "Not Found...";
      cv.elements.searchResults.appendChild(div);
    }
  };

  //送信
  xhr.send(form);

  cv.removeChildElements(cv.elements.searchResults);

  var div = document.createElement("div");
  div.className = "search-results-header";
  div.appendChild(cv.createLoader());
  cv.elements.searchResults.appendChild(div);
}

ContentsViewer.sendRating = (button) => {
  var cv = ContentsViewer;
  var rating = button.getAttribute('data-value');
  var form = new FormData();
  form.append("cmd", 'rate');
  form.append("contentPath", cv.vars.contentPath);
  form.append("rating", rating);
  form.append("otp", cv.vars.otp);

  var xhr = new XMLHttpRequest();
  xhr.open("POST", cv.vars.serviceUri + "/feedback-service.php", true);
  xhr.onload = function (e) {
    try {
      if (!cv.validateResponse(this)) {
        throw "Sorry... Internal Error occured.";
      }

      if (this.parsedResponse.error) {
        throw this.parsedResponse.error;
      }
    }
    catch (err) {
      console.error(err);
      return;
    }
  }

  var survey = document.getElementById('content-survey');
  document.querySelector('#content-survey .button-group').style.display = 'none';
  var title = document.querySelector('#content-survey .title');


  if (rating > 2) {
    title.textContent = document.querySelector('#content-survey input[name="thanks"]').value;
    document.querySelector('#content-survey .any-feedback').style.display = 'block';
  }
  else {
    title.textContent = document.querySelector('#content-survey input[name="sorry"').value;
    document.querySelector('#content-survey .how-improve').style.display = 'block';
  }

  var field = document.createElement('div');
  field.classList.add('field');
  var textarea = document.createElement('textarea');
  field.appendChild(textarea);
  survey.appendChild(field);
  var button = document.createElement('button');
  button.textContent = 'Submit';
  button.onclick = function () { cv.sendMessage() };
  button.classList.add('submit-button');
  survey.appendChild(button);
  xhr.send(form);
}

ContentsViewer.sendMessage = () => {
  var cv = ContentsViewer;
  var message = document.querySelector('#content-survey textarea').value;
  var form = new FormData();
  form.append("cmd", 'message');
  form.append("contentPath", cv.vars.contentPath);
  form.append("message", message);
  form.append("otp", cv.vars.otp);

  var xhr = new XMLHttpRequest();
  xhr.open("POST", cv.vars.serviceUri + "/feedback-service.php", true);
  xhr.onload = function (e) {
    try {
      if (!cv.validateResponse(this)) {
        throw "Sorry... Internal Error occured.";
      }

      if (this.parsedResponse.error) {
        throw this.parsedResponse.error;
      }
    }
    catch (err) {
      console.error(err);
      return;
    }
  }

  var survey = document.getElementById('content-survey');
  survey.classList.add('submitted');
  document.querySelector('#content-survey .how-improve').style.display = 'none';
  document.querySelector('#content-survey .any-feedback').style.display = 'none';

  xhr.send(form);
}

ContentsViewer.onClickLayerSelector = (element, event) => {
  if (element.parentNode.hasAttribute('open')) {
    return;
  }

  var closeClickHandler = function () {
    element.parentNode.removeAttribute('open');
  }

  event.stopPropagation();
  element.parentNode.setAttribute('open', '');
  document.addEventListener('click', closeClickHandler, { once: true, capture: false });
}

ContentsViewer.openExternalLinksInNewWindow = (link) => {
  if (link.href.indexOf(location.protocol + '//' + location.hostname) != 0) {
    link.target = '_blank';
  }
}

ContentsViewer.validateResponse = (xhr) => {
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

ContentsViewer.notBlankText = (texts) => {
  for (var i = 0; i < texts.length; i++) {
    if (texts[i] != '') {
      return texts[i];
    }
  }

  return texts[texts.length - 1];
}

ContentsViewer.createLoader = () => {
  var loader = document.createElement("div");
  loader.className = "loader dot-floating";
  loader.appendChild(document.createElement("div"));
  loader.appendChild(document.createElement("div"));
  loader.appendChild(document.createElement("div"));
  return loader;
}

ContentsViewer.private.initOnHead();
document.addEventListener("DOMContentLoaded", ContentsViewer.private.initOnDOMLoaded);


