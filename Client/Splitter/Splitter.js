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

    this.sliderWidth = 7;

    this.slider = this.CreateSliderElement();
    document.body.appendChild(this.slider);

    // alert(direction);

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

  CreateSliderElement() {
    var slider = document.createElement("div");
    slider.style.position = "absolute";
    slider.style.background = "#ddd";
    slider.style.border = "1px inset #aaa";
    slider.style.cursor = "move";
    slider.style.zIndex = "100";

    slider.splitter = this;
    slider.addEventListener("mousedown", Splitter.MouseDown, {
      capture: false
    });
    slider.addEventListener("touchstart", Splitter.MouseDown, {
      passive: false,
      capture: false
    });

    return slider;
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
      (this.sliderWidth / document.documentElement.clientWidth) * 100;
    var marginY =
      (this.sliderWidth / document.documentElement.clientHeight) * 100;

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

      if (this.onResizeElementACallbackFunc != null) {
        this.onResizeElementACallbackFunc();
      }
    }

    if (this.childA != null) {
      this.childA.rect = rectA;
      this.childA.Resize();
    }

    if (this.elementB != null) {
      Splitter.SetElementRect(this.elementB, rectB);

      if (this.onResizeElementBCallbackFunc != null) {
        this.onResizeElementBCallbackFunc();
      }
    }

    if (this.childB != null) {
      this.childB.rect = rectB;
      this.childB.Resize();
    }

    if (this.direction == Splitter.Direction.Horizontal) {
      this.slider.style.left = this.rect.position.x + "%";
      this.slider.style.top =
        this.rect.position.y + (this.rect.size.y * this.percent) / 100.0 + "%";
      this.slider.style.width = this.rect.size.x + "%";
      this.slider.style.height = this.sliderWidth + "px";
    } else {
      this.slider.style.left =
        this.rect.position.x + (this.rect.size.x * this.percent) / 100.0 + "%";
      this.slider.style.top = this.rect.position.y + "%";
      this.slider.style.width = this.sliderWidth + "px";
      this.slider.style.height = this.rect.size.y + "%";
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

  //マウスが押された際の関数
  // this: slider html object
  static MouseDown(e) {
    //alert(this);
    //クラス名に .drag を追加
    this.classList.add("drag");

    //タッチデイベントとマウスのイベントの差異を吸収
    if (e.type === "mousedown") {
      var event = e;
    } else {
      var event = e.changedTouches[0];
      //
    }
    e.preventDefault();

    //alert(this.test);
    //要素内の相対座標を取得
    this.fromX = event.pageX - this.offsetLeft;
    this.fromY = event.pageY - this.offsetTop;
    //alert(this.fromX);

    //ムーブイベントにコールバック
    document.body.addEventListener("mousemove", Splitter.MouseMove, {
      capture: false
    });
    document.body.addEventListener("touchmove", Splitter.MouseMove, {
      passive: false,
      capture: false
    });
  }

  //マウスカーソルが動いたときに発火
  // this: body object
  static MouseMove(e) {
    //alert(this);
    //ドラッグしている要素を取得
    var drag = document.getElementsByClassName("drag")[0];
    //drag = this.slider;

    //同様にマウスとタッチの差異を吸収
    if (e.type === "mousemove") {
      var event = e;
    } else {
      var event = e.changedTouches[0];
    }

    //フリックしたときに画面を動かさないようにデフォルト動作を抑制
    e.preventDefault();

    //マウスが動いた場所に要素を動かす

    // drag.style.top = event.pageY - drag.fromY + "px";
    // drag.style.left = event.pageX - drag.fromX + "px";
    var top =
      ((event.pageY - drag.fromY) / document.documentElement.clientHeight) *
      100;
    var left =
      ((event.pageX - drag.fromX) / document.documentElement.clientWidth) * 100;

    if (drag.splitter.direction == Splitter.Direction.Horizontal) {
      if (top < drag.splitter.rect.position.y) {
        top = drag.splitter.rect.position.y;
      }

      if (top > drag.splitter.rect.position.y + drag.splitter.rect.size.y) {
        top = drag.splitter.rect.position.y + drag.splitter.rect.size.y;
      }

      drag.style.top = top + "%";
      drag.splitter.percent =
        ((top - drag.splitter.rect.position.y) / drag.splitter.rect.size.y) *
        100;
      //alert(top);
    } else {
      if (left < drag.splitter.rect.position.x) {
        left = drag.splitter.rect.position.x;
      }

      if (left > drag.splitter.rect.position.x + drag.splitter.rect.size.x) {
        left = drag.splitter.rect.position.x + drag.splitter.rect.size.x;
      }

      drag.style.left = left + "%";
      drag.splitter.percent =
        ((left - drag.splitter.rect.position.x) / drag.splitter.rect.size.x) *
        100;
    }

    //マウスボタンが離されたとき、またはカーソルが外れたとき発火
    drag.addEventListener("mouseup", Splitter.MouseUp, { capture: false });
    document.body.addEventListener("mouseleave", Splitter.MouseUp, {
      capture: false
    });
    drag.addEventListener("touchend", Splitter.MouseUp, { capture: false });
    document.body.addEventListener("touchleave", Splitter.MouseUp, {
      capture: false
    });
  }

  //マウスボタンが上がったら発火
  static MouseUp(e) {
    var drag = document.getElementsByClassName("drag")[0];
    //drag = this.slider;

    //alert(drag)
    //alert(drag.splitter.percent);
    drag.splitter.Resize();

    //ムーブベントハンドラの消去
    document.body.removeEventListener("mousemove", Splitter.MouseMove, {
      capture: false
    });
    drag.removeEventListener("mouseup", Splitter.MouseUp, { capture: false });

    document.body.removeEventListener("touchmove", Splitter.MouseMove, {
      passive: false,
      capture: false
    });
    drag.removeEventListener("touchend", Splitter.MouseUp, { capture: false });

    document.body.removeEventListener("mouseleave", Splitter.MouseUp, {
      capture: false
    });
    document.body.removeEventListener("touchleave", Splitter.MouseUp, {
      capture: false
    });

    //クラス名 .drag も消す
    drag.classList.remove("drag");
  }
}

Splitter.Direction = { Vertical: 0, Horizontal: 1 };
Splitter.Side = { A: 0, B: 1 };
