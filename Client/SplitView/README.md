# Split View

Completely Element-Based Split View Controller.

Respect Element data (e.g. attribute, style) and structure.

## Features

### No Layout Changes on page loaded
Before the script is loaded, the layout is determined, and the script set the style based on current layout. The layout is never changed. 


### Hackable
The script have no any states (e.g. split direction, split size) and gets them only when a handle is grabbed. Before handle start to be dragged, anyone can edit the elements freely.


### Customaizable
The script never style the elements and never add or remove the elements. You can design your own elements as you want.

## Quick Start

### 1. Prepare base css

```css
.gutter {
  background-color: #eee;
  background-repeat: no-repeat;
  background-position: 50%;
  flex: none;
}

.split-view {
  display: flex;
}

.split-view.vertical {
  flex-direction: column;
}

.split-view.horizontal {
  flex-direction: row;
}

.split-view>*:not(.gutter){
  flex-grow: 1;
  overflow: scroll;
}

.split-view>.gutter {
  background-color: #eee;
  background-repeat: no-repeat;
  background-position: 50%;
}

.split-view.horizontal>.gutter {
  background-image: url('data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAUAAAAeCAYAAADkftS9AAAAIklEQVQoU2M4c+bMfxAGAgYYmwGrIIiDjrELjpo5aiZeMwF+yNnOs5KSvgAAAABJRU5ErkJggg==');
  cursor: col-resize;
  width: 10px;
}

.split-view.vertical>.gutter {
  background-image: url('data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAB4AAAAFAQMAAABo7865AAAABlBMVEVHcEzMzMzyAv2sAAAAAXRSTlMAQObYZgAAABBJREFUeF5jOAMEEAIEEFwAn3kMwcB6I2AAAAAASUVORK5CYII=');
  cursor: row-resize;
  height: 10px;
}
```

### 2. Make the layout

```html
<div id="main" class="split-view horizontal">
  <div>
    <h1>Hello</h1> 
  </div>
  <div class="gutter"></div>
  <div class="split-view vertical">
    <div>
      <h1>World</h1>
    </div>
    <div class="gutter"></div>
    <div>
      <h1>🎉</h1>
    </div>
  </div>
</div>
```

### 3. Activate

```html
<script src="SplitView.js"></script>
<script>
  SplitView.activate(document.getElementById("main"))
</script>
```
