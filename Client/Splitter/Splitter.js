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

    this.gutterWidth = 7;

    this.gutter = this.CreateGutterElement();
    document.body.appendChild(this.gutter);

    this.Resize();
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

  static SetElementRect(element, rect) {
    element.style.left = rect.position.x + "%";
    element.style.top = rect.position.y + "%";
    element.style.width = rect.size.x + "%";
    element.style.height = rect.size.y + "%";
  }

  CreateGutterElement() {
    var gutter = document.createElement("div");
    gutter.style.position = "absolute";
    gutter.style.background = "#ddd";
    gutter.style.border = "1px solid #BBB";
    gutter.style.cursor = (this.direction == Splitter.Direction.Horizontal)
      ? "row-resize" : "col-resize";
    gutter.classList.add("gutter");
    gutter.style.zIndex = "100";
    var dragHandler = document.createElement("div");
    dragHandler.style.position = "absolute";
    dragHandler.style.top = "0";
    dragHandler.style.bottom = "0";
    dragHandler.style.left = "0";
    dragHandler.style.right = "0";
    dragHandler.style.cursor = "inherit";
    dragHandler.addEventListener("mousedown", Splitter.MouseDown, {
      capture: false
    });
    dragHandler.addEventListener("touchstart", Splitter.MouseDown, {
      passive: false,
      capture: false
    });
    gutter.appendChild(dragHandler);
    gutter.dragHandler = dragHandler;
    gutter.splitter = this;
    return gutter;
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
    if (this.direction == Splitter.Direction.Horizontal) {
      return new Rect(
        new Vector2(this.rect.position.x, this.rect.position.y),
        new Vector2(this.rect.size.x, (this.rect.size.y * this.percent) / 100.0)
      );
    } else {
      return new Rect(
        new Vector2(this.rect.position.x, this.rect.position.y),
        new Vector2((this.rect.size.x * this.percent) / 100.0, this.rect.size.y)
      );
    }
  }

  RectB() {
    var marginX =
      (this.gutterWidth / document.documentElement.clientWidth) * 100;
    var marginY =
      (this.gutterWidth / document.documentElement.clientHeight) * 100;

    if (this.direction == Splitter.Direction.Horizontal) {
      return new Rect(
        new Vector2(
          this.rect.position.x,
          this.rect.position.y +
          (this.rect.size.y * this.percent) / 100.0 +
          marginY
        ),
        new Vector2(
          this.rect.size.x,
          (this.rect.size.y * (100 - this.percent)) / 100.0 - marginY
        )
      );
    } else {
      return new Rect(
        new Vector2(
          this.rect.position.x +
          (this.rect.size.x * this.percent) / 100.0 +
          marginX,
          this.rect.position.y
        ),
        new Vector2(
          (this.rect.size.x * (100 - this.percent)) / 100.0 - marginX,
          this.rect.size.y
        )
      );
    }
  }

  Resize() {
    var rectA = this.RectA();
    var rectB = this.RectB();

    if (this.elementA != null) {
      Splitter.SetElementRect(this.elementA, rectA);
      this.onResizeElementACallbackFunc?.();
    }

    if (this.childA != null) {
      this.childA.rect = rectA;
      this.childA.Resize();
    }

    if (this.elementB != null) {
      Splitter.SetElementRect(this.elementB, rectB);
      this.onResizeElementBCallbackFunc?.();
    }

    if (this.childB != null) {
      this.childB.rect = rectB;
      this.childB.Resize();
    }

    if (this.direction == Splitter.Direction.Horizontal) {
      this.gutter.style.left = this.rect.position.x + "%";
      this.gutter.style.top =
        this.rect.position.y + (this.rect.size.y * this.percent) / 100.0 + "%";
      this.gutter.style.width = this.rect.size.x + "%";
      this.gutter.style.height = this.gutterWidth + "px";
    } else {
      this.gutter.style.left =
        this.rect.position.x + (this.rect.size.x * this.percent) / 100.0 + "%";
      this.gutter.style.top = this.rect.position.y + "%";
      this.gutter.style.width = this.gutterWidth + "px";
      this.gutter.style.height = this.rect.size.y + "%";
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
    } else {
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
    } else {
      this.elementB = null;
      this.onResizeElementBCallbackFunc = null;
      this.childB = childSplitter;
    }

    return childSplitter;
  }

  // It will be called on click of drag handler.
  static MouseDown(e) {
    var dragHandler = this;
    var gutter = dragHandler.parentNode;
    var splitter = gutter.splitter;

    // bind touch and click events.
    if (e.type === "mousedown") {
      var event = e;
    } else {
      var event = e.changedTouches[0];
    }
    e.preventDefault();

    // get/set relative coordinates
    gutter.fromX = event.pageX - gutter.offsetLeft;
    gutter.fromY = event.pageY - gutter.offsetTop;

    gutter.style.zIndex = parseInt(gutter.style.zIndex) + "1";

    dragHandler.style.top = "-100px";
    dragHandler.style.bottom = "-100px";
    dragHandler.style.left = "-100px";
    dragHandler.style.right = "-100px";

    dragHandler.addEventListener(
      "mousemove", Splitter.MouseMove, {
        capture: false
    });
    dragHandler.addEventListener(
      "touchmove", Splitter.MouseMove, {
      passive: false,
      capture: false
    });

    dragHandler.addEventListener(
      "mouseup", Splitter.MouseUp, { capture: false });
    dragHandler.addEventListener(
      "mouseleave", Splitter.MouseUp, {
      capture: false
    });
    dragHandler.addEventListener(
      "touchend", Splitter.MouseUp, { capture: false });
    dragHandler.addEventListener(
      "touchleave", Splitter.MouseUp, {
      capture: false
    });
  }


  static MouseMove(e) {
    Math.clamp = function (val, min, max) { return Math.max(min, Math.min(max, val)); }

    var dragHandler = this;
    var gutter = dragHandler.parentNode;
    var splitter = gutter.splitter;

    // bind touch and click events.
    if (e.type === "mousemove") {
      var event = e;
    } else {
      var event = e.changedTouches[0];
    }

    // prevent screen scrolling
    e.preventDefault();

    var top = ((event.pageY - gutter.fromY) / document.documentElement.clientHeight) * 100;
    var left = ((event.pageX - gutter.fromX) / document.documentElement.clientWidth) * 100;

    if (splitter.direction == Splitter.Direction.Horizontal) {
      top = Math.clamp(
        top,
        splitter.rect.position.y,
        splitter.rect.position.y + splitter.rect.size.y
      );
      gutter.style.top = top + "%";
      splitter.percent = ((top - splitter.rect.position.y) / splitter.rect.size.y) * 100;
    } else {
      left = Math.clamp(
        left,
        splitter.rect.position.x,
        splitter.rect.position.x + splitter.rect.size.x
      );
      gutter.style.left = left + "%";
      splitter.percent = ((left - splitter.rect.position.x) / splitter.rect.size.x) * 100;
    }
  }


  static MouseUp(e) {
    var dragHandler = this;
    var gutter = dragHandler.parentNode;
    var splitter = gutter.splitter;
    
    splitter.Resize();
    
    gutter.style.zIndex = parseInt(gutter.style.zIndex) - "1";

    dragHandler.style.top = "0";
    dragHandler.style.bottom = "0";
    dragHandler.style.left = "0";
    dragHandler.style.right = "0";
    
    dragHandler.removeEventListener(
      "mousemove",
      Splitter.MouseMove, {
      capture: false
    });
    dragHandler.removeEventListener(
      "mouseup",
      Splitter.MouseUp, {
      capture: false
    });

    dragHandler.removeEventListener(
      "touchmove",
      Splitter.MouseMove, {
      passive: false,
      capture: false
    });
    dragHandler.removeEventListener(
      "touchend",
      Splitter.MouseUp, {
      capture: false
    });

    dragHandler.removeEventListener(
      "mouseleave", Splitter.MouseUp, {
      capture: false
    });
    dragHandler.removeEventListener(
      "touchleave", Splitter.MouseUp, {
      capture: false
    });
  }
}

Splitter.Direction = { Vertical: 0, Horizontal: 1 };
Splitter.Side = { A: 0, B: 1 };
