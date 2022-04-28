; (function () {
  "use strict"

  const HORIZONTAL = "horizontal"
  const VERTICAL = "vertical"

  const CLASS_GUTTER = "gutter"
  const CLASS_SPLIT_VIEW = "split-view"
  const CLASS_HORIZONTAL = "horizontal"
  const CLASS_VERTICAL = "vertical"

  const NOOP = () => false

  // Current drag context.
  // This context is created at the start of the drag and shared while dragging and at the end of the drag.
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

    if (!direction) { return null }

    const viewA = element.children[0]
    const gutter = element.children[1]
    const viewB = element.children[2]

    if (!viewA || !viewB) { return null; }
    if (!gutter || !gutter.classList.contains(CLASS_GUTTER)) { return null }

    const gutterStyle = getComputedStyle(gutter)

    let clientAxis, positionAxis, dimension

    if (direction === HORIZONTAL) {
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
      direction: direction,
      element: element,
      gutter: gutter,
      viewA: viewA,
      viewB: viewB,
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

  function dragStartHandler(e) {
    // console.log('[dragStartHandler]', e)

    // Already dragging
    if (dragContext) { return }

    // Right-clicking can't start dragging.
    if ('button' in e && e.button !== 0) {
      return
    }

    const gutter = this
    const splitView = parseSplitView(gutter.parentNode)

    if (!splitView) {
      console.warn('this gutter has no valid split view', gutter)
      return
    }

    // Make the drag context. 
    // This context is shared while dragging and at the end of the drag.
    dragContext = {
      splitView: splitView
    }

    e.preventDefault()

    const { viewA, viewB } = splitView

    if (e.type === "mousedown") {
      window.addEventListener("mousemove", dragHandler)
      window.addEventListener("mouseup", dragEndHandler)
      window.addEventListener("mouseleave", dragEndHandler)
    }
    else {
      window.addEventListener("touchmove", dragHandler)
      window.addEventListener("touchend", dragEndHandler)
      window.addEventListener("touchleave", dragEndHandler)
    }

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

    document.body.style.cursor = splitView.cursor
  }

  function dragHandler(e) {
    // console.log('[dragHandler]', e)
    e.preventDefault()

    const { splitView } = dragContext
    const { gutter, dimension, positionAxis, viewA, viewB } = splitView

    const splitViewBounds = splitView.element.getBoundingClientRect()
    const gutterBounds = gutter.getBoundingClientRect()

    const mousePosition = splitView.getMousePosition(e)

    let percent = (mousePosition - splitViewBounds[positionAxis]) / splitViewBounds[dimension] * 100

    // clamp 0 ~ 100
    percent = percent < 0 ? 0 : percent < 100 ? percent : 100

    viewA.style[dimension] = `calc(${percent}% - ${gutterBounds[dimension] / 2}px)`
    viewB.style[dimension] = `calc(${100 - percent}% - ${gutterBounds[dimension] / 2}px)`
  }

  function dragEndHandler(e) {
    // console.log('[dragEndHandler]', e)
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


  SplitView.activate = function (element) {
    const splitView = parseSplitView(element)
    if (splitView) {
      const { positionAxis, dimension, gutter, viewA, viewB } = splitView

      const splitViewBounds = splitView.element.getBoundingClientRect()
      const gutterBounds = gutter.getBoundingClientRect()

      let percent = (gutterBounds[positionAxis] + gutterBounds[dimension] / 2 - splitViewBounds[positionAxis]) / splitViewBounds[dimension] * 100

      // clamp 0 ~ 100
      percent = percent < 0 ? 0 : percent < 100 ? percent : 100

      viewA.style[dimension] = `calc(${percent}% - ${gutterBounds[dimension] / 2}px)`
      viewB.style[dimension] = `calc(${100 - percent}% - ${gutterBounds[dimension] / 2}px)`

      gutter.addEventListener("mousedown", dragStartHandler)
      gutter.addEventListener("touchstart", dragStartHandler)

      this.activate(viewA)
      this.activate(viewB)
    }
  }

  // export
  window.SplitView = SplitView
})()
