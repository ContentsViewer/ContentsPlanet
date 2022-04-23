; (function () {
  "use strict"

  const HORIZONTAL = "horizontal"
  const VERTICAL = "vertical"

  const CLASS_GUTTER = "gutter"
  const CLASS_SPLIT_VIEW = "split-view"
  const CLASS_HORIZONTAL = "horizontal"
  const CLASS_VERTICAL = "vertical"

  const NOOP = () => false


  const DragContext = (gutter) => {
    const splitView = parseSplitView(gutter.parentNode)
    if (!splitView) { return null; }

    const gutterStyle = getComputedStyle(gutter)

    let clientAxis, positionAxis, dimension

    if (splitView.direction === HORIZONTAL) {
      clientAxis = 'clientX'
      positionAxis = 'x'
      dimension = 'width'
    }
    else {
      clientAxis = 'clientY'
      positionAxis = 'y'
      dimension = 'height'
    }

    return {
      gutter: gutter,
      splitView: splitView,
      cursor: gutterStyle.cursor,
      clientAxis: clientAxis,
      positionAxis: positionAxis,
      dimension: dimension,
      getMousePosition: function (e) {
        if ('touches' in e) { return e.touches[0][this.clientAxis] }
        return e[this.clientAxis]
      }
    }
  }

  // current drag context
  var dragContext = null


  function parseSplitView(element) {
    if (!element.classList.contains(CLASS_SPLIT_VIEW)) {
      return null
    }

    const direction = element.classList.contains(CLASS_HORIZONTAL)
      ? HORIZONTAL
      : element.classList.contains(CLASS_VERTICAL)
        ? VERTICAL
        : null

    if (!direction) {
      return null
    }

    const viewA = element.children[0]
    const gutter = element.children[1]
    const viewB = element.children[2]

    if (!viewA || !viewB) { return null; }
    if (!gutter || !gutter.classList.contains(CLASS_GUTTER)) { return null; }

    return {
      direction: direction,
      element: element,
      gutter: gutter,
      viewA: viewA,
      viewB: viewB
    }
  }


  function dragStartHandler(e) {

    if (dragContext) {
      console.log("already dragging")
      return
    }

    // Right-clicking can't start dragging.
    if ('button' in e && e.button !== 0) {
      return
    }

    const gutter = this
    dragContext = DragContext(gutter)

    if (!dragContext) {
      console.warn('failed to make drag context. maybe invalid gutter given', gutter)
      return
    }

    e.preventDefault()

    const { viewA, viewB } = dragContext.splitView

    window.addEventListener("mousemove", dragHandler, { capture: false })
    window.addEventListener("touchmove", dragHandler, { passive: false, capture: false, })

    window.addEventListener("mouseup", dragEndHandler)
    window.addEventListener("mouseleave", dragEndHandler)
    window.addEventListener("touchend", dragEndHandler)
    window.addEventListener("touchleave", dragEndHandler)

    // Disable selection. Disable!
    viewA.addEventListener('selectstart', NOOP)
    viewA.addEventListener('dragstart', NOOP)
    viewB.addEventListener('selectstart', NOOP)
    viewB.addEventListener('dragstart', NOOP)

    viewA.style.userSelect = 'none'
    viewA.style.webkitUserSelect = 'none'
    viewA.style.MozUserSelect = 'none'
    viewA.style.pointerEvents = 'none'

    viewB.style.userSelect = 'none'
    viewB.style.webkitUserSelect = 'none'
    viewB.style.MozUserSelect = 'none'
    viewB.style.pointerEvents = 'none'

    document.body.style.cursor = dragContext.cursor
  }

  function dragHandler(e) {
    e.preventDefault()

    const { splitView, gutter, dimension, positionAxis } = dragContext

    const splitViewBounds = splitView.element.getBoundingClientRect()
    const gutterBounds = gutter.getBoundingClientRect()

    const mousePosition = dragContext.getMousePosition(e)

    let percent = (mousePosition - splitViewBounds[positionAxis]) / splitViewBounds[dimension] * 100

    // clamp 0 ~ 100
    percent = percent < 0 ? 0 : percent < 100 ? percent : 100

    splitView.viewA.style[dimension] = `calc(${percent}% - 5px)`
    splitView.viewB.style[dimension] = `calc(${100 - percent}% - 5px)`
  }

  function dragEndHandler(e) {
    const { viewA, viewB } = dragContext.splitView

    window.removeEventListener("mousemove", dragHandler)
    window.removeEventListener("touchmove", dragHandler)

    window.removeEventListener("mouseup", dragEndHandler)
    window.removeEventListener("mouseleave", dragEndHandler)
    window.removeEventListener("touchend", dragEndHandler)
    window.removeEventListener("touchleave", dragEndHandler)

    viewA.removeEventListener('selectstart', NOOP)
    viewA.removeEventListener('dragstart', NOOP)
    viewB.removeEventListener('selectstart', NOOP)
    viewB.removeEventListener('dragstart', NOOP)

    viewA.style.userSelect = ''
    viewA.style.webkitUserSelect = ''
    viewA.style.MozUserSelect = ''
    viewA.style.pointerEvents = ''

    viewB.style.userSelect = ''
    viewB.style.webkitUserSelect = ''
    viewB.style.MozUserSelect = ''
    viewB.style.pointerEvents = ''

    document.body.style.cursor = ''

    // discard current drag context
    dragContext = null
  }

  var SplitView = {}

  SplitView.build = function (element) {
    const splitView = parseSplitView(element)
    if (splitView) {
      splitView.gutter.addEventListener("mousedown", dragStartHandler)
      splitView.gutter.addEventListener("touchstart", dragStartHandler)

      this.build(splitView.viewA)
      this.build(splitView.viewB)
    }
  }

  // export
  window.SplitView = SplitView
})()
