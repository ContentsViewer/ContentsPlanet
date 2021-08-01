
class Vector2 {
  constructor(x, y) {
    this.x = x == null ? 0 : x;
    this.y = y == null ? 0 : y;
  }
}

class Rect {
  constructor(position, size) {
    this.position = position == null ? new Vector2(0, 0) : position;
    this.size = size == null ? new Vector2(0, 0) : size;
  }
}

class Splitter {
  constructor(direction, elementA, elementB, options) {
    this.direction = direction;

    this.elementA = elementA;
    this.elementB = elementB;

    this.childA = null;
    this.childB = null;

    if (options == null) {
      options = {};
    }

    var defaultOptions = Splitter.DefaultOptions();
    for (var key in defaultOptions) {
      if (!(key in options)) {
        options[key] = defaultOptions[key];
      }
    }

    this.onResizeElementACallbackFunc = options["onResizeElementACallbackFunc"];
    this.onResizeElementBCallbackFunc = options["onResizeElementBCallbackFunc"];
    this.parent = options["parent"];
    this.percent = options["percent"];
    this.rect = options["rect"];

    this.gutter = this.CreateGutterElement();

    this.viewportParent = null

    if (this.elementA) {
      this.viewportParent = this.elementA.parentNode
    }
    else if (this.elementB) {
      this.viewportParent = this.elementB.parentNode
    }
    else {
      this.viewportParent = document.body
    }
    this.viewportParent.appendChild(this.gutter);

    this.Resize();
  }

  PercentHeight(px) {
    const { viewportParent } = this
    return px / viewportParent.offsetHeight * 100
  }

  PercentWidth(px) {
    const { viewportParent } = this
    return px / viewportParent.offsetWidth * 100
  }

  //
  // +----------------------------+
  // |     elementA or childA     |
  // +----------------------------+
  // |     elementB or childB     |
  // +----------------------------+
  //

  //
  // +----------+----------+
  // |          |          |
  // | elementA | elementB |
  // |    or    |    or    |
  // |  childA  |  childB  |
  // |          |          |
  // +----------|----------+
  //

  RectA() {
    const { direction, rect, percent, gutter } = this
    
    if (direction == Splitter.Direction.Horizontal) {
      const viewHeight = rect.size.y - this.PercentHeight(gutter.width)
      return new Rect(
        new Vector2(rect.position.x, rect.position.y),
        new Vector2(rect.size.x, viewHeight * percent / 100.0)
      );
    }
    else {
      const viewWidth = rect.size.x - this.PercentWidth(gutter.width)
      return new Rect(
        new Vector2(rect.position.x, rect.position.y),
        new Vector2(viewWidth * percent / 100.0, this.rect.size.y)
      );
    }
  }

  RectB() {
    const { direction, rect, percent, gutter } = this
    if (direction == Splitter.Direction.Horizontal) {
      const viewHeight = rect.size.y - this.PercentHeight(gutter.width)
      const height = viewHeight * (100 - percent) / 100
      return new Rect(
        new Vector2(rect.position.x, rect.position.y + rect.size.y - height),
        new Vector2(rect.size.x, height)
      );
    }
    else {
      const viewWidth = rect.size.x - this.PercentWidth(gutter.width)
      const width = viewWidth * (100 - percent) / 100
      return new Rect(
        new Vector2(rect.position.x + rect.size.x - width, rect.position.y),
        new Vector2(width, rect.size.y)
      );
    }
  }

  Resize() {
    const { rect, gutter, percent, direction } = this
    
    const rectA = this.RectA();
    const rectB = this.RectB();

    if (this.elementA) {
      Splitter.SetElementRect(this.elementA, rectA);
      if (this.onResizeElementACallbackFunc) {
        this.onResizeElementACallbackFunc();
      }
    }

    if (this.childA) {
      this.childA.rect = rectA;
      this.childA.Resize();
    }

    if (this.elementB) {
      Splitter.SetElementRect(this.elementB, rectB);
      if (this.onResizeElementBCallbackFunc) {
        this.onResizeElementBCallbackFunc();
      }
    }

    if (this.childB) {
      this.childB.rect = rectB;
      this.childB.Resize();
    }

    if (direction == Splitter.Direction.Horizontal) {
      const viewHeight = rect.size.y - this.PercentHeight(gutter.width)
      gutter.style.left = `${rect.position.x}%`;
      gutter.style.top = `${rect.position.y + viewHeight * percent / 100}%`;
      gutter.style.width = `${rect.size.x}%`;
    }
    else {
      const viewWidth = rect.size.x - this.PercentWidth(gutter.width)
      gutter.style.left = `${rect.position.x + viewWidth * percent / 100}%`;
      gutter.style.top = `${rect.position.y}%`;
      gutter.style.height = `${rect.size.y}%`;
    }
  }

  Split(
    side,
    direction,
    elementToPutEmptyArea,
    percent,
    onResizeElementCallbackFunc
  ) {
    if (side == Splitter.Side.A && this.elementA == null) {
      return null;
    }

    if (side == Splitter.Side.B && this.elementB == null) {
      return null;
    }

    if (percent == null) {
      percent = Splitter.DefaultOptions()["percent"];
    }

    var childRect = null;

    if (side == Splitter.Side.A) {
      childRect = this.RectA();
    }
    else {
      childRect = this.RectB();
    }

    var options = {
      parent: this,
      percent: percent,
      rect: childRect,
      onResizeElementACallbackFunc:
        side == Splitter.Side.A
          ? this.onResizeElementACallbackFunc
          : this.onResizeElementBCallbackFunc,
      onResizeElementBCallbackFunc: onResizeElementCallbackFunc
    };

    var childSplitter = new Splitter(
      direction,
      side == Splitter.Side.A ? this.elementA : this.elementB,
      elementToPutEmptyArea,
      options
    );

    if (side == Splitter.Side.A) {
      this.elementA = null;
      this.onResizeElementACallbackFunc = null;
      this.childA = childSplitter;
    }
    else {
      this.elementB = null;
      this.onResizeElementBCallbackFunc = null;
      this.childB = childSplitter;
    }

    return childSplitter;
  }

  static DefaultOptions() {
    return {
      parent: null,
      percent: 50,
      rect: new Rect(new Vector2(0, 0), new Vector2(100, 100)),
      onResizeElementACallbackFunc: null,
      onResizeElementBCallbackFunc: null
    };
  }

  CreateGutterElement() {
    const gutter = document.createElement("div");
    gutter.classList.add("gutter");
    gutter.width = 7
    gutter.style.position = "absolute";
    gutter.style.background = "#ddd";
    gutter.style.border = "1px solid #BBB";
    gutter.style.zIndex = "100";
    gutter.style.boxSizing = 'border-box'
    if (this.direction == Splitter.Direction.Horizontal) {
      gutter.style.cursor = "row-resize"
      gutter.style.height = `${gutter.width}px`
    }
    else {
      gutter.style.cursor = "col-resize"
      gutter.style.width = `${gutter.width}px`
    }

    const dragHandler = document.createElement("div");
    dragHandler.style.position = "absolute";
    dragHandler.style.top = "0";
    dragHandler.style.bottom = "0";
    dragHandler.style.left = "0";
    dragHandler.style.right = "0";
    dragHandler.style.cursor = "inherit";
    // dragHandler.style.border = "1px solid red"
    dragHandler.addEventListener(
      "mousedown", Splitter.MouseDown, { capture: false }
    );
    dragHandler.addEventListener(
      "touchstart", Splitter.MouseDown, { passive: false, capture: false }
    );
    gutter.appendChild(dragHandler);
    gutter.dragHandler = dragHandler;
    gutter.splitter = this;
    return gutter;
  }

  static SetElementRect(element, rect) {
    element.style.left = `${rect.position.x}%`;
    element.style.top = `${rect.position.y}%`;
    element.style.width = `${rect.size.x}%`;
    element.style.height = `${rect.size.y}%`;
  }


  // It will be called on click of drag handler.
  static MouseDown(e) {
    // bind touch and click events.
    e.preventDefault();
    if (e.type === "mousedown") {
      var event = e;
    }
    else {
      var event = e.changedTouches[0];
    }

    const dragHandler = this;
    const gutter = dragHandler.parentNode;
    const splitter = gutter.splitter;

    // get relative coordinates
    gutter.fromX = event.pageX - gutter.offsetLeft;
    gutter.fromY = event.pageY - gutter.offsetTop;

    gutter.style.zIndex = parseInt(gutter.style.zIndex) + 1;

    dragHandler.style.top = "-100px";
    dragHandler.style.bottom = "-100px";
    dragHandler.style.left = "-100px";
    dragHandler.style.right = "-100px";

    dragHandler.addEventListener(
      "mousemove", Splitter.MouseMove, { capture: false }
    );
    dragHandler.addEventListener(
      "touchmove", Splitter.MouseMove, { passive: false, capture: false }
    );

    dragHandler.addEventListener(
      "mouseup", Splitter.MouseUp, { capture: false }
    );
    dragHandler.addEventListener(
      "mouseleave", Splitter.MouseUp, { capture: false }
    );
    dragHandler.addEventListener(
      "touchend", Splitter.MouseUp, { capture: false }
    );
    dragHandler.addEventListener(
      "touchleave", Splitter.MouseUp, { capture: false }
    );
  }

  static MouseMove(e) {
    // prevent screen scrolling
    e.preventDefault();
    // bind touch and click events.
    if (e.type === "mousemove") {
      var event = e;
    }
    else {
      var event = e.changedTouches[0];
    }

    const clamp = function (val, min, max) { return Math.max(min, Math.min(max, val)); }

    const dragHandler = this;
    const gutter = dragHandler.parentNode;
    const splitter = gutter.splitter;
    const { viewportParent } = splitter

    if (splitter.direction == Splitter.Direction.Horizontal) {
      let top = ((event.pageY - viewportParent.offsetTop - gutter.fromY) / viewportParent.offsetHeight) * 100;
      top = clamp(
        top,
        splitter.rect.position.y,
        splitter.rect.position.y + splitter.rect.size.y
      );
      gutter.style.top = `${top}%`;
      splitter.percent = ((top - splitter.rect.position.y) / splitter.rect.size.y) * 100;
    }
    else {
      let left = ((event.pageX - viewportParent.offsetLeft - gutter.fromX) / viewportParent.offsetWidth) * 100;
      left = clamp(
        left,
        splitter.rect.position.x,
        splitter.rect.position.x + splitter.rect.size.x
      );
      gutter.style.left = `${left}%`;
      splitter.percent = ((left - splitter.rect.position.x) / splitter.rect.size.x) * 100;
    }
  }


  static MouseUp(e) {
    const dragHandler = this;
    const gutter = dragHandler.parentNode;
    const splitter = gutter.splitter;

    splitter.percent = Math.max(0, Math.min(splitter.percent, 100))
    splitter.Resize();

    gutter.style.zIndex = parseInt(gutter.style.zIndex) - 1;

    dragHandler.style.top = "0";
    dragHandler.style.bottom = "0";
    dragHandler.style.left = "0";
    dragHandler.style.right = "0";

    dragHandler.removeEventListener(
      "mousemove", Splitter.MouseMove, { capture: false }
    );
    dragHandler.removeEventListener(
      "mouseup", Splitter.MouseUp, { capture: false }
    );

    dragHandler.removeEventListener(
      "touchmove", Splitter.MouseMove, { passive: false, capture: false }
    );
    dragHandler.removeEventListener(
      "touchend", Splitter.MouseUp, { capture: false }
    );

    dragHandler.removeEventListener(
      "mouseleave", Splitter.MouseUp, { capture: false }
    );
    dragHandler.removeEventListener(
      "touchleave", Splitter.MouseUp, { capture: false }
    );
  }
}

Splitter.Direction = { Vertical: 0, Horizontal: 1 };
Splitter.Side = { A: 0, B: 1 };
