;(function () {
  "use strict"

  var CV = {}

  var sectionListInColumn = []
  var sectionListInMainContent = []

  function getVisibleText(element) {
    var text = ""

    ;(function walk(node) {
      if (node.nodeType == Node.ELEMENT_NODE) {
        var style = window.getComputedStyle(node)
        if (style.display == "none" || style.visibility == "hidden") {
          return
        }
      }

      if (node.childNodes.length == 0) {
        text += node.textContent
      }

      for (var i = 0; i < node.childNodes.length; ++i) {
        walk(node.childNodes[i])
      }
    })(element)
    return text
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
  function createSectionTreeHelper(element, navi, idBegin, sectionListInColumn, sectionListInMainContent) {
    var ul = document.createElement("ul")

    for (var i = 0; i < element.children.length; i++) {
      var child = element.children[i]

      if (child.tagName == "H2" || child.tagName == "H3" || child.tagName == "H4") {
        child.setAttribute("id", "SectionID_" + idBegin)

        var section = document.createElement("li")
        var link = document.createElement("a")
        link.textContent = getVisibleText(child)
        link.href = "#SectionID_" + idBegin
        section.appendChild(link)
        ul.appendChild(section)
        ;(function (target, link) {
          var observer = new MutationObserver(mutations => {
            link.textContent = getVisibleText(target)
          })
          observer.observe(target, { characterData: true, subtree: true })
        })(child, link)

        sectionListInColumn.push(link)
        idBegin++

        if (i + 1 < element.children.length && element.children[i + 1].className == "section") {
          // heading + div(section) per one set.
          sectionListInMainContent.push(child)
          sectionListInMainContent.push(element.children[i + 1])

          idBegin = createSectionTreeHelper(element.children[i + 1], section, idBegin, sectionListInColumn, sectionListInMainContent)
        } else {
          sectionListInMainContent.push(child)
          sectionListInMainContent.push(null)
        }
      }
    }

    if (ul.children.length > 0) {
      navi.appendChild(ul)
    }
    return idBegin
  }

  function setupOutline() {
    let item

    // Need contentBody and rightColumn.
    if (!CV.elements.contentBodyText || !CV.elements.rightColumn) {
      return
    }

    var docOutlineNavi = (item = CV.elements.rightColumn.getElementsByClassName("navi")) ? item[0] : undefined
    if (!docOutlineNavi) {
      return
    }

    var naviEmbeded = docOutlineNavi.cloneNode(true)
    naviEmbeded.removeAttribute("class")
    var navWrapper = document.querySelector("#doc-outline-embeded>.nav-wrapper")
    navWrapper.appendChild(naviEmbeded)

    if (CV.elements.contentBodyText.children.length != 0) {
      if (createSectionTreeHelper(CV.elements.contentBodyText, docOutlineNavi, 0, sectionListInColumn, sectionListInMainContent) != 0) {
        // Note: firstChild returns any type of node that is the first child of this one.
        //  It may be a Text or a Comment node.
        //  If you want to get the first Element that is a child of another element,
        //  consider using Element.firstElementChild.
        //  For more details, see
        //  * https://developer.mozilla.org/en-US/docs/Web/API/Node/firstChild
        docOutlineNavi.removeChild(docOutlineNavi.firstElementChild)
      }

      if (createSectionTreeHelper(CV.elements.contentBodyText, naviEmbeded, 0, [], []) != 0) {
        naviEmbeded.removeChild(naviEmbeded.firstElementChild)
      }
    }

    var listItems = naviEmbeded.getElementsByTagName("li")
    var maxVisibleCount = 5
    for (var i = 0, loop = Math.min(maxVisibleCount, listItems.length); i < loop; i++) {
      listItems[i].setAttribute("visible", "")
    }
    if (listItems.length <= maxVisibleCount) {
      var toggleDocOutlineLabel = document.querySelector("#doc-outline-embeded>label")
      toggleDocOutlineLabel.style.display = "none"
    }
  }

  function setupLeftColumn() {
    if (CV.elements.leftColumn) {
      CV.elements.leftColumn.querySelectorAll(".selected").forEach(function (value, index) {
        value.scrollIntoView({ block: "center" })
      })
    }
    if (CV.elements.leftColumnResponsive) {
      CV.elements.leftColumnResponsive.querySelectorAll(".selected").forEach(function (value, index) {
        value.scrollIntoView({ block: "center" })
      })
    }
  }

  function setupSectionHeadings() {
    var sectionHeadings = document.querySelectorAll("#content-body h2")
    var expanded = true

    for (var i = 0; i < sectionHeadings.length; i++) {
      var heading = sectionHeadings[i]
      var controlId = "content-collapsible-block-" + i

      heading.setAttribute("aria-haspopup", "true")
      heading.setAttribute("aria-controls", controlId)
      heading.setAttribute("tabindex", "0")
      heading.setAttribute("aria-expanded", expanded)

      if (!expanded) {
        heading.classList.add("close-block")
      }

      heading.addEventListener("click", function (event) {
        if (this.classList.contains("close-block")) {
          CV.toggleBlockExpanded(this, true)
        } else {
          CV.toggleBlockExpanded(this, false)
        }
      })

      heading.addEventListener("keypress", function (event) {
        // スペースかエンターが押されているかを確認
        if (event.key === " " || event.key === "Enter") {
          // スペースが押されたときにスクロールさせないためにデフォルトの振る舞いをキャンセル
          event.preventDefault()
          if (this.classList.contains("close-block")) {
            CV.toggleBlockExpanded(this, true)
          } else {
            CV.toggleBlockExpanded(this, false)
          }
        }
      })

      if (heading.nextSibling && heading.nextSibling.classList.contains("section")) {
        var section = heading.nextSibling
        section.id = controlId
        section.setAttribute("aria-hidden", !expanded)
      }
    }
  }

  var setupRelatedView = (function () {
    var relatedViewObserver

    return () => {
      if (CV.elements.relatedResults) {
        relatedViewObserver = new IntersectionObserver(entries => {
          // If intersectionRatio is 0, the target is out of view
          // and we do not need to do anything.
          if (entries[0].intersectionRatio <= 0) {
            return
          }
          CV.getRelatedContents()
          relatedViewObserver.disconnect()
        })
        relatedViewObserver.observe(CV.elements.relatedResults)
      }
    }
  })()

  function setupSearching() {
    CV.elements.searchResultsParent.removeChild(CV.elements.searchResults)
    CV.elements.searchBoxInputClearButton.style.display = "none"
  }

  var updateCurrentSectionSelection = (function () {
    var currents = {}

    return (sectionListInColumn, sectionListInMainContent) => {
      var nexts = {}
      var updated = false

      for (var i = 0; i < sectionListInMainContent.length; i += 2) {
        var heading = sectionListInMainContent[i]
        var section = sectionListInMainContent[i + 1]
        var top, bottom

        if (heading) {
          var rect = heading.getBoundingClientRect()
          top = rect.top
          bottom = rect.bottom
        }
        if (section) {
          var rect = section.getBoundingClientRect()
          bottom = rect.bottom
        }

        if (top < window.innerHeight / 3 && window.innerHeight / 3 < bottom) {
          if (!(i in currents)) {
            updated = true
          }
          nexts[i] = true
        }
      }

      if (updated) {
        for (var i in currents) {
          sectionListInColumn[Math.floor(i / 2)].classList.remove("selected")
        }

        for (var i in nexts) {
          sectionListInColumn[Math.floor(i / 2)].classList.add("selected")
          sectionListInColumn[Math.floor(i / 2)].scrollIntoView({ block: "nearest" })
        }
        currents = nexts
      }
    }
  })()

  CV.jumpToHash = function (hash) {
    if (!hash) {
      return false
    }

    hash = hash.substr(1)
    hash = decodeURIComponent(hash)

    var target = document.getElementById(hash)
    if (!target) {
      target = document.querySelector('[name="' + hash + '"]')
    }
    if (!target) {
      return false
    }

    var iterator = target
    while (iterator) {
      if (!iterator || iterator === CV.elements.contentBodyText || iterator === document.body) {
        return false
      }

      if (iterator.hasAttribute("aria-hidden")) {
        var controller = document.querySelector('[aria-controls="' + iterator.id + '"]')
        CV.toggleBlockExpanded(controller, true)
        CV.getScrollElement().scrollTop = CV.getElementOffset(target).top
        return true
      }

      if (iterator.hasAttribute("aria-haspopup")) {
        CV.toggleBlockExpanded(iterator, true)
        CV.getScrollElement().scrollTop = CV.getElementOffset(target).top
        return true
      }

      iterator = iterator.parentElement
    }
    return false
  }

  CV.toggleBlockExpanded = function (controller, expanded) {
    if (expanded) {
      controller.classList.remove("close-block")
    } else {
      controller.classList.add("close-block")
    }

    controller.setAttribute("aria-expanded", expanded)
    var controlId = controller.getAttribute("aria-controls")
    var control = document.getElementById(controlId)

    control.setAttribute("aria-hidden", !expanded)
  }

  CV.getScrollElement = function () {
    if ("scrollingElement" in document) {
      return document.scrollingElement
    }
    if (navigator.userAgent.indexOf("WebKit") != -1) {
      return document.body
    }
    return document.documentElement
  }

  CV.getElementOffset = function (element) {
    var rect = element.getBoundingClientRect()
    return {
      top: rect.top + (window.pageYOffset || document.documentElement.scrollTop),
      left: rect.left + (window.pageXOffset || document.documentElement.scrollLeft),
    }
  }

  CV.onClickPullUpButton = function () {
    CV.elements.pullDownMenuButton.style.display = "block"
    CV.elements.pullUpMenuButton.style.display = "none"
    CV.elements.pullDownMenu.setAttribute("aria-hidden", "true")
    CV.elements.header.classList.remove("pull-down")
  }

  CV.onClickPullDownButton = function (event) {
    event.stopPropagation()

    CV.elements.pullDownMenuButton.style.display = "none"
    CV.elements.pullUpMenuButton.style.display = "block"
    CV.elements.pullDownMenu.setAttribute("aria-hidden", "false")
    CV.elements.header.classList.add("pull-down")

    var onClickOuterOfHeader = function (e) {
      e.stopPropagation()
      var element = e.target
      while (element) {
        if (element === CV.elements.header || element === CV.elements.searchOverlay) {
          document.addEventListener("click", onClickOuterOfHeader, { once: true, capture: false })
          return
        }
        element = element.parentNode
      }
      CV.onClickPullUpButton()
    }
    document.addEventListener("click", onClickOuterOfHeader, { once: true, capture: false })
  }

  CV.closeWarningMessageBox = function () {
    if (!CV.elements.warningMessageBox) {
      return
    }

    CV.elements.warningMessageBox.style.animationName = "warning-message-box-slideout"
    CV.elements.warningMessageBox = null
  }

  CV.onClickSearchButton = function (query) {
    CV.elements.searchResultsParent.appendChild(CV.elements.searchResults)
    CV.elements.searchOverlay.classList.add("visible")
    document.body.classList.add("overlay-enabled")
    CV.elements.searchBoxInput.focus()
    if (query) {
      CV.elements.searchBoxInput.value = query
      CV.onInputSearchBox(true)
    }
  }

  CV.onClickSearchBoxInputClearButton = function () {
    CV.elements.searchBoxInput.value = ""
    CV.elements.searchBoxInput.focus()
    CV.onInputSearchBox(true)
  }

  CV.onClickSearchOverlayCloseButton = function () {
    CV.elements.searchResultsParent.removeChild(CV.elements.searchResults)
    CV.elements.searchOverlay.classList.remove("visible")
    document.body.classList.remove("overlay-enabled")
    scrollTo(0, 0)
  }

  CV.onClickThemeChangeButton = function () {
    var currentTheme = ThemeChanger.getCurrentTheme()
    if (currentTheme === "dark") {
      ThemeChanger.clearTheme()
    } else {
      ThemeChanger.setTheme("dark")
    }
    ThemeChanger.changeTheme()
  }

  CV.onChangeMenuOpen = function (input) {
    if (input.checked) {
      CV.openLeftColumn()
    } else {
      CV.closeLeftColumn()
    }
  }

  CV.openLeftColumn = function () {
    CV.elements.menuOpenInput.checked = true
    CV.elements.leftColumnResponsive.classList.add("left-column-responsive-open")

    document.body.style.overflow = "hidden"
    CV.elements.leftColumnResponsive.style.zIndex = "99999"
    CV.elements.menuOpenButton.style.zIndex = "99999"
    CV.elements.sitemask.setAttribute("visible", "")
  }

  CV.closeLeftColumn = function () {
    CV.elements.menuOpenInput.checked = false
    CV.elements.leftColumnResponsive.classList.remove("left-column-responsive-open")

    document.body.style.overflow = "auto"

    CV.elements.leftColumnResponsive.style.zIndex = "990"
    CV.elements.menuOpenButton.style.zIndex = "990"
    CV.elements.sitemask.removeAttribute("visible")
  }

  CV.onClickSitemask = function () {
    CV.closeLeftColumn()
  }

  CV.removeChildElements = function (parent) {
    while (parent.firstChild) parent.removeChild(parent.firstChild)
  }

  CV.getRelatedContents = function () {
    var form = new FormData()
    form.append("contentPath", CV.vars.contentPath)
    form.append("token", CV.vars.token)

    var xhr = new XMLHttpRequest()
    xhr.open("POST", CV.vars.serviceUri + "/related-search-service.php", true)
    xhr.onload = function (e) {
      CV.removeChildElements(CV.elements.relatedResults)
      try {
        if (!CV.validateResponse(this)) {
          throw "Sorry... Internal Error occured."
        }

        if (this.parsedResponse.error) {
          throw this.parsedResponse.error
        }
      } catch (err) {
        var div = document.createElement("div")
        div.className = "related-results-header"
        div.textContent = err
        CV.elements.relatedResults.appendChild(div)
        return
      }

      var related = this.parsedResponse.related
      if (related.length > 0) {
        related.forEach(each => {
          var wrapper = document.createElement("div")
          wrapper.className = "card-wrapper"

          var head = document.createElement("a")
          head.className = "card-item head"
          var inner = document.createElement("div")
          inner.className = "inner"
          var title = document.createElement("div")
          title.className = "title"
          title.textContent = each.keyword
          inner.appendChild(title)

          if (each.detailURL !== false) {
            head.href = each.detailURL
          }

          if (each.type == "tag") {
            head.classList.add("tag")
            var icon = document.createElement("div")
            icon.className = "tag-icon icon"
            inner.appendChild(icon)
          } else if (each.type == "link") {
            head.classList.add("link")
            var icon = document.createElement("div")
            icon.className = "link-icon icon"
            inner.appendChild(icon)
          }
          head.appendChild(inner)
          wrapper.appendChild(head)

          each.contents.forEach(content => {
            var item = document.createElement("div")
            item.className = "card-item"
            var hoverLink = document.createElement("a")
            hoverLink.className = "hover-link"
            hoverLink.href = content.url
            var inner = document.createElement("div")
            inner.className = "inner"
            var title = document.createElement("a")
            title.href = content.url
            title.className = "title"
            title.textContent =
              CV.notBlankText([content.title, content.url.split("/").pop()]) +
              (content.parentTitle === false ? "" : " | " + CV.notBlankText([content.parentTitle, content.parentURL.split("/").pop()]))
            inner.appendChild(title)
            var summary = document.createElement("div")
            summary.innerHTML = content.summary
            summary.className = "summary"
            inner.appendChild(summary)
            item.appendChild(inner)
            item.appendChild(hoverLink)
            wrapper.appendChild(item)
          })
          CV.elements.relatedResults.appendChild(wrapper)

          var splitter = document.createElement("div")
          splitter.className = "splitter"
          CV.elements.relatedResults.appendChild(splitter)
        })
      } else {
        var div = document.createElement("div")
        div.className = "related-results-header"
        div.textContent = "Not Found..."
        CV.elements.relatedResults.appendChild(div)
      }
    }

    // window.setTimeout(function () { xhr.send(form) }, 1000 ) // delay 1 sec
    xhr.send(form)
    CV.removeChildElements(CV.elements.relatedResults)
    var div = document.createElement("div")
    div.className = "related-results-header"
    div.appendChild(CV.createLoader())
    CV.elements.relatedResults.appendChild(div)
  }

  CV.onInputSearchBox = (function () {
    var timer = null

    return (updateResultsImmediately = false) => {
      if (timer) {
        clearTimeout(timer)
      }

      if (CV.elements.searchBoxInput.value) {
        CV.elements.searchBoxInputClearButton.style.display = "block"
      } else {
        CV.elements.searchBoxInputClearButton.style.display = "none"
      }

      if (updateResultsImmediately) {
        CV.updateSearchResults()
      } else {
        timer = setTimeout(function () {
          timer = null
          CV.updateSearchResults()
        }, 1000)
      }
    }
  })()

  CV.updateSearchResults = function () {
    var form = new FormData()
    form.append("contentPath", CV.vars.contentPath)
    form.append("token", CV.vars.token)
    form.append("query", CV.elements.searchBoxInput.value.replace("　", " "))

    var xhr = new XMLHttpRequest()
    xhr.open("POST", CV.vars.serviceUri + "/contents-search-service.php", true)
    xhr.onload = function (e) {
      CV.removeChildElements(CV.elements.searchResults)

      try {
        if (!CV.validateResponse(this)) {
          throw "Sorry... Internal Error occured."
        }

        if (this.parsedResponse.error) {
          throw this.parsedResponse.error
        }
      } catch (err) {
        var div = document.createElement("div")
        div.className = "search-results-header"
        div.textContent = err
        CV.elements.searchResults.appendChild(div)
        return
      }

      if (this.parsedResponse.suggestions.length > 0) {
        var ul = document.createElement("ul")
        ul.className = "child-list"

        for (var i = 0; i < this.parsedResponse.suggestions.length; i++) {
          var suggestion = this.parsedResponse.suggestions[i]
          var li = document.createElement("li")
          var divWrapper = document.createElement("div")

          var divTitle = document.createElement("div")
          divTitle.className = "child-title"

          var a = document.createElement("a")
          a.href = suggestion.url
          a.innerHTML =
            CV.notBlankText([suggestion.title, suggestion.url.split("/").pop()]) +
            (suggestion.parentTitle === false ? "" : " | " + CV.notBlankText([suggestion.parentTitle, suggestion.parentUrl.split("/").pop()]))
          divTitle.appendChild(a)

          var divSummary = document.createElement("div")
          divSummary.className = "child-summary"
          divSummary.innerHTML = suggestion.summary

          divWrapper.appendChild(divTitle)
          divWrapper.appendChild(divSummary)
          li.appendChild(divWrapper)
          ul.appendChild(li)
        }

        CV.elements.searchResults.appendChild(ul)
      } else {
        var div = document.createElement("div")
        div.className = "search-results-header"
        div.textContent = "Not Found..."
        CV.elements.searchResults.appendChild(div)
      }
    }

    //送信
    xhr.send(form)

    CV.removeChildElements(CV.elements.searchResults)

    var div = document.createElement("div")
    div.className = "search-results-header"
    div.appendChild(CV.createLoader())
    CV.elements.searchResults.appendChild(div)
  }

  CV.sendRating = function (button) {
    var rating = button.getAttribute("data-value")
    var form = new FormData()
    form.append("cmd", "rate")
    form.append("contentPath", CV.vars.contentPath)
    form.append("rating", rating)
    form.append("otp", CV.vars.otp)

    var xhr = new XMLHttpRequest()
    xhr.open("POST", CV.vars.serviceUri + "/feedback-service.php", true)
    xhr.onload = function (e) {
      try {
        if (!CV.validateResponse(this)) {
          throw "Sorry... Internal Error occured."
        }

        if (this.parsedResponse.error) {
          throw this.parsedResponse.error
        }
      } catch (err) {
        console.error(err)
        return
      }
    }

    var survey = document.getElementById("content-survey")
    document.querySelector("#content-survey .button-group").style.display = "none"
    var title = document.querySelector("#content-survey .title")

    if (rating > 2) {
      title.textContent = document.querySelector('#content-survey input[name="thanks"]').value
      document.querySelector("#content-survey .any-feedback").style.display = "block"
    } else {
      title.textContent = document.querySelector('#content-survey input[name="sorry"').value
      document.querySelector("#content-survey .how-improve").style.display = "block"
    }

    var field = document.createElement("div")
    field.classList.add("field")
    var textarea = document.createElement("textarea")
    field.appendChild(textarea)
    survey.appendChild(field)
    var button = document.createElement("button")
    button.textContent = "Submit"
    button.onclick = function () {
      CV.sendMessage()
    }
    button.classList.add("submit-button")
    survey.appendChild(button)
    xhr.send(form)
  }

  CV.sendMessage = function () {
    var message = document.querySelector("#content-survey textarea").value
    var form = new FormData()
    form.append("cmd", "message")
    form.append("contentPath", CV.vars.contentPath)
    form.append("message", message)
    form.append("otp", CV.vars.otp)

    var xhr = new XMLHttpRequest()
    xhr.open("POST", CV.vars.serviceUri + "/feedback-service.php", true)
    xhr.onload = function (e) {
      try {
        if (!CV.validateResponse(this)) {
          throw "Sorry... Internal Error occured."
        }

        if (this.parsedResponse.error) {
          throw this.parsedResponse.error
        }
      } catch (err) {
        console.error(err)
        return
      }
    }

    var survey = document.getElementById("content-survey")
    survey.classList.add("submitted")
    document.querySelector("#content-survey .how-improve").style.display = "none"
    document.querySelector("#content-survey .any-feedback").style.display = "none"

    xhr.send(form)
  }

  CV.onClickLayerSelector = function (element, event) {
    if (element.parentNode.hasAttribute("open")) {
      return
    }

    var closeClickHandler = function () {
      element.parentNode.removeAttribute("open")
    }

    event.stopPropagation()
    element.parentNode.setAttribute("open", "")
    document.addEventListener("click", closeClickHandler, { once: true, capture: false })
  }

  CV.openExternalLinksInNewWindow = function (link) {
    if (link.href.indexOf(location.protocol + "//" + location.hostname) != 0) {
      link.target = "_blank"
    }
  }

  CV.validateResponse = function (xhr) {
    if (xhr.status != 200) {
      console.error("Lost server.")
      return false
    }

    xhr.parsedResponse = null
    try {
      xhr.parsedResponse = JSON.parse(xhr.response)
    } catch (error) {
      console.error("Fatal Error in the server.\n" + xhr.response)
      return false
    }

    return true
  }

  CV.notBlankText = function (texts) {
    for (var i = 0; i < texts.length; i++) {
      if (texts[i] != "") {
        return texts[i]
      }
    }

    return texts[texts.length - 1]
  }

  CV.createLoader = function () {
    var loader = document.createElement("div")
    loader.className = "loader dot-floating"
    loader.appendChild(document.createElement("div"))
    loader.appendChild(document.createElement("div"))
    loader.appendChild(document.createElement("div"))
    return loader
  }
  ;(function () {
    CV.vars = {}

    let item
    CV.vars.token = (item = document.getElementsByName("token").item(0)) ? item.content : undefined
    CV.vars.contentPath = (item = document.getElementsByName("content-path").item(0)) ? item.content : undefined
    CV.vars.serviceUri = (item = document.getElementsByName("service-uri").item(0)) ? item.content : undefined
    CV.vars.otp = (item = document.getElementsByName("otp").item(0)) ? item.content : undefined

    CV.elements = {}
    CV.elements.header = document.querySelector("#header")
    CV.elements.pullDownMenuButton = document.querySelector("#pull-down-menu-button")
    CV.elements.pullUpMenuButton = document.querySelector("#pull-up-menu-button")
    CV.elements.warningMessageBox = document.getElementById("warning-message-box")
    CV.elements.leftColumn = document.getElementById("left-column")
    CV.elements.leftColumnResponsive = document.getElementById("left-column-responsive")
    CV.elements.menuOpenInput = document.getElementById("menu-open")
    CV.elements.sitemask = document.getElementById("sitemask")
    CV.elements.menuOpenButton = document.getElementsByClassName("menu-open-button-wrapper")[0]
    CV.elements.contentBody = document.getElementById("content-body")
    CV.elements.contentBodyText = document.querySelector("#content-body>.outlinetext-parser-output")
    CV.elements.pullDownMenu = document.getElementById("pull-down-menu")
    CV.elements.searchOverlay = document.getElementById("search-overlay")
    CV.elements.searchResults = document.getElementById("search-results")
    CV.elements.searchResultsParent = CV.elements.searchResults.parentNode
    CV.elements.searchBoxInput = document.getElementById("search-box-input")
    CV.elements.searchBoxInputClearButton = document.getElementById("search-box-input-clear-button")
    CV.elements.relatedView = document.getElementById("related-view")
    CV.elements.relatedResults = document.getElementById("related-results")
    CV.elements.rightColumn = document.getElementById("right-column")
    CV.elements.docOutlineEmbeded = document.getElementById("doc-outline-embeded")

    if (!CV.elements.contentBodyText) {
      // もし, '.outlinetext-parser-output'が見つけられなかった場合,
      // その親である, 'contentBody'要素を設定する
      CV.elements.contentBodyText = CV.elements.contentBody
    }

    setupSearching()
    setupRelatedView()
    setupOutline()
    setupLeftColumn()
    setupSectionHeadings()

    // init hash-changing processings.
    window.addEventListener(
      "hashchange",
      function (event) {
        if (CV.jumpToHash(window.location.hash)) {
          event.preventDefault()
        }
      },
      false
    )
    CV.jumpToHash(window.location.hash)

    window.addEventListener("resize", function () {
      if (CV.elements.menuOpenInput && CV.elements.menuOpenInput.checked) {
        CV.closeLeftColumn()
      }
    })

    var onScroll = (function () {
      var offsetYToHideHeader = 50
      var sumOfScroll = 0
      var isHiddenHeader = false
      var timer = null
      var scrollPosPrev = window.pageYOffset

      return () => {
        sumOfScroll += window.pageYOffset - scrollPosPrev
        if (Math.abs(sumOfScroll) > offsetYToHideHeader) {
          CV.closeWarningMessageBox()
        }

        if (window.pageYOffset < offsetYToHideHeader) {
          if (isHiddenHeader) {
            CV.elements.header.style.animationName = "appear-header-anim"
            isHiddenHeader = false
          }
        } else {
          if (!isHiddenHeader) {
            CV.elements.header.style.animationName = "hide-header-anim"
            CV.onClickPullUpButton()
            isHiddenHeader = true
          }
        }

        scrollPosPrev = window.pageYOffset

        if (timer) {
          return
        }
        timer = setTimeout(function () {
          timer = null
          updateCurrentSectionSelection(sectionListInColumn, sectionListInMainContent)
        }, 200)
      }
    })()

    // init scroll processings.
    window.addEventListener("scroll", onScroll)
    onScroll()
  })()

  window.ContentsViewer = CV
})()
