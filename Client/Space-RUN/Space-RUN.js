var canvas = document.getElementById("game-canvas");
var container = document.getElementById("game-canvas-container");
var ctx = canvas.getContext("2d");
var button = document.getElementById("game-button");
var panel = document.getElementById("game-panel");
var panelTitle = document.getElementById("game-panel-title");
var panelContent = document.getElementById("game-panel-content");

//親要素のサイズをCanvasに指定
canvas.width = container.clientWidth;
canvas.height = container.clientHeight;

var renderDepth = 20;
var renderNearPlane = 0.01;
var wallSpeed = 0.1;
var bulletSpeed = 0.5;
var bulletRadius = 0.05;
var player = { x: 0, y: 0, z: 1, radius: 0.03 };
var viewScale = canvas.height / 2;
var score = 0;
var scoreMultiplier = 1;
var bulletCooldown = 6;
var gameover = true;
var highscoreSaveKey = "space-run-game-highscore";
var highscore = 0;
var gameplayStartLoopCounter = 0;

var GameState = {
  Gameover: 1,
  Gameplay: 2,
  Idle: 3
};
var gameState = GameState.Idle;

var config = {
  onBeginGameplay: function() {},
  onBeginIdle: function() {},
  onBeginGameover: function() {},
  wallStrokeColor: { r: 0, g: 0, b: 0 },
  normalObstacleColor: { r: 0, g: 0, b: 0 },
  hardObstacleColor: { r: 0, g: 127, b: 255 },
  scoreTextColor: { r: 0, g: 0, b: 0 },
  bulletColor: { r: 0, g: 0, b: 0 }
};

// var temp = 1;
// {z, }
var walls = [];
resetWalls();

var bullets = [];

var touchPositionPrevious = { x: 0, y: 0 };

document.addEventListener(
  "keydown",
  function(e) {
    if (e.keyCode == 32) {
      if (gameState == GameState.Idle) {
        beginGameplay();
      }
    }
  },
  false
);

canvas.addEventListener(
  "mousemove",
  function(e) {
    switch (gameState) {
      case GameState.Gameplay:
        var relativeX = e.clientX - canvas.offsetLeft;
        if (relativeX > 0 && relativeX < canvas.width) {
          player.x = -1 + (2 * relativeX) / canvas.width;
        }
        var relativeY = e.clientY - canvas.offsetTop;
        if (relativeY > 0 && relativeY < canvas.height) {
          player.y = -1 + (2 * relativeY) / canvas.height;
        }

        clampInsideUnitCircle(player);
        break;
    }
  },
  false
);

var touchStartTime = new Date();
canvas.addEventListener(
  "touchstart",
  function(e) {
    switch (gameState) {
      case GameState.Idle:
        touchStartTime = new Date();
        break;
      case GameState.Gameplay:
        e.preventDefault();
        var touch = e.touches[0];
        touchPositionPrevious = {
          x: touch.clientX - canvas.offsetLeft,
          y: touch.clientY - canvas.offsetTop
        };
        break;
    }
  },
  false
);

canvas.addEventListener(
  "touchend",
  function(e) {
    // e.preventDefault();
    switch (gameState) {
      case GameState.Idle:
        var now = new Date();
        if (now.getTime() - touchStartTime.getTime() > 1000) {
          beginGameplay();
        }
        break;
    }
  },
  false
);

canvas.addEventListener(
  "touchmove",
  function(e) {
    switch (gameState) {
      case GameState.Gameplay:
        e.preventDefault();
        var touch = e.touches[0];
        touchPosition = {
          x: touch.clientX - canvas.offsetLeft,
          y: touch.clientY - canvas.offsetTop
        };

        player.x += (touchPosition.x - touchPositionPrevious.x) * 0.01;
        player.y += (touchPosition.y - touchPositionPrevious.y) * 0.01;

        clampInsideUnitCircle(player);

        touchPositionPrevious = touchPosition;
        break;
    }
  },
  false
);

button.onclick = function() {
  switch (gameState) {
    default:
    case GameState.Gameover:
      resetWalls();
      score = 0;
      beginGameplay();
      break;
  }
};

// function getCookie(cname) {
//     var name = cname + "=";
//     var decodedCookie = decodeURIComponent(document.cookie);
//     var ca = decodedCookie.split(';');
//     for (var i = 0; i < ca.length; i++) {
//         var c = ca[i];
//         while (c.charAt(0) == ' ') {
//             c = c.substring(1);
//         }
//         if (c.indexOf(name) == 0) {
//             return c.substring(name.length, c.length);
//         }
//     }
//     return "";
// }

function beginGameplay() {
  gameState = GameState.Gameplay;
  panel.classList.add("hide-panel");

  if (window.localStorage) {
    highscore = parseInt(window.localStorage.getItem(highscoreSaveKey));
    if (!highscore) highscore = 0;
  }

  gameplayStartLoopCounter = 0;
  config.onBeginGameplay();
}

function beginIdle() {
  gameState = GameState.Idle;
  panelTitle.textContent = "Space-RUN";
  panelContent.innerHTML =
    "Press space key to start... <br/> // or Tap deeply the world";
  button.style.display = "none";
  panel.classList.remove("hide-panel");
  config.onBeginIdle();
}

function beginGameover() {
  gameState = GameState.Gameover;
  panelTitle.textContent = "//GAME OVER//";
  panelContent.textContent = "";

  button.textContent = "RETRY";
  button.style.display = "block";

  panel.classList.remove("hide-panel");

  if (highscore < score) {
    if (window.localStorage) {
      window.localStorage.setItem(highscoreSaveKey, score);
    }
    highscore = score;
  }

  config.onBeginGameover();
}

function clampInsideUnitCircle(vector) {
  if (vector.x != 0 && vector.y != 0) {
    var magnitude = Math.sqrt(vector.x * vector.x + vector.y * vector.y);

    if (magnitude > 1) {
      vector.x /= magnitude;
      vector.y /= magnitude;
    }
  }
}

function randomInsideUnitCircle() {
  // 極座標から単位円内のランダム点を求める.
  // ヤコビアン注意
  // dxdy = rdrd\theta
  u = Math.random() * 0.5; // u = random(max=0.5 * R^2)
  r = Math.sqrt(2 * u);
  theta = Math.random() * Math.PI * 2;
  return { x: r * Math.cos(theta), y: r * Math.sin(theta) };
}

function resetWalls() {
  walls = [{ x: 0, y: 0, z: 0, radius: 1, obstacle: { enabled: false } }];
  while (walls[walls.length - 1].z < renderDepth) {
    walls.push({
      x: 0,
      y: 0,
      z: walls[walls.length - 1].z + 1,
      radius: 1,
      obstacle: { enabled: false }
    });
  }
}

function generateWall(createObstacle) {
  if (walls.length > renderDepth) {
    return;
  }

  var randomPosition = randomInsideUnitCircle();

  var width = lerp(0.2, 1.8, Math.random());
  var height = 2 - width;
  for (var count = 0; count < Math.ceil(Math.random() * 2); count++) {
    var isHard = Math.random() > 0.8;

    walls.push({
      x: 0,
      y: 0,
      z: walls[walls.length - 1].z + 1,
      radius: 1,
      obstacle: {
        enabled: createObstacle,
        x: randomPosition.x,
        y: randomPosition.y,
        width: width,
        height: height,
        // x: 0.5,
        // y: 0.5,
        // width: 1,
        // height: 1,
        hp: isHard ? 100 : 1,
        scale: 1,
        type: isHard ? "hard" : "normal"
      }
    });
  }
}

function calculateFogAmount(depth) {
  return Math.exp((-5 * depth) / renderDepth);
  // return 1 - (depth / renderDepth);
}

function drawWalls() {
  for (var i = walls.length - 1; i >= 0; i--) {
    var wall = walls[i];
    if (wall.z > renderDepth) {
      continue;
    }
    if (wall.z < renderNearPlane) {
      continue;
    }
    var depth = wall.z;
    var fog = calculateFogAmount(depth);

    ctx.save();
    ctx.beginPath();
    ctx.arc(
      canvas.width / 2 + (viewScale * wall.x) / depth,
      canvas.height / 2 + (viewScale * wall.y) / depth,
      (viewScale * wall.radius) / depth,
      0,
      Math.PI * 2
    );

    // ctx.strokeStyle = "rgba(195, 223, 218," + fog + ")";
    ctx.strokeStyle =
      "rgba(" +
      config.wallStrokeColor.r +
      ", " +
      config.wallStrokeColor.g +
      ", " +
      config.wallStrokeColor.b +
      "," +
      fog +
      ")";

    ctx.closePath();
    ctx.stroke();
    ctx.clip();
    // ctx.fillStyle = "rgba(226, 115, 93," + (fog * 0.3) + ")";
    if (wall.obstacle.enabled) {
      var obstacle = wall.obstacle;
      var red = config.normalObstacleColor.r;
      var green = config.normalObstacleColor.g;
      var blue = config.normalObstacleColor.b;

      if (obstacle.type == "hard") {
        red = config.hardObstacleColor.r;
        green = config.hardObstacleColor.g;
        blue = config.hardObstacleColor.b;
      } else if (obstacle.type == "collided") {
        red = 255;
        green = 0;
        blue = 0;
      }

      ctx.fillStyle =
        "rgba(" +
        red +
        "," +
        green +
        "," +
        blue +
        "," +
        fog *
          lerp(0, 0.3, depth - renderNearPlane / (player.z - renderNearPlane)) +
        ")";

      ctx.fillRect(
        canvas.width / 2 +
          (viewScale * (obstacle.x - (obstacle.width / 2) * obstacle.scale)) /
            depth,
        canvas.height / 2 +
          (viewScale * (obstacle.y - (obstacle.height / 2) * obstacle.scale)) /
            depth,
        ((obstacle.width * obstacle.scale) / depth) * viewScale,
        ((obstacle.height * obstacle.scale) / depth) * viewScale
      );
    }
    ctx.restore();
  }
}

function clamp01(f) {
  if (f < 0) return 0;
  if (f > 1) return 1;
  return f;
}

function lerp(a, b, t) {
  t = clamp01(t);
  return a + (b - a) * t;
}

var bulletCooldownCounter = 0;
function emitBullet() {
  if (++bulletCooldownCounter < bulletCooldown) return;
  bulletCooldownCounter = 0;

  bullets.push({
    x: player.x,
    y: player.y,
    z: player.z,
    radius: bulletRadius,
    enabled: true
  });
}

function drawBullets() {
  for (var i = bullets.length - 1; i >= 0; i--) {
    var bullet = bullets[i];
    if (!bullet.enabled) continue;

    var depth = bullet.z;
    var fog = calculateFogAmount(depth);
    ctx.beginPath();
    ctx.arc(
      canvas.width / 2 + (viewScale * bullet.x) / depth,
      canvas.height / 2 + (viewScale * bullet.y) / depth,
      (viewScale * bullet.radius) / depth,
      0,
      Math.PI * 2
    );
    // ctx.strokeStyle = "rgba(195, 223, 218," + fog + ")";
    ctx.strokeStyle =
      "rgba(" +
      config.bulletColor.r +
      ", " +
      config.bulletColor.g +
      ", " +
      config.bulletColor.b +
      "," +
      fog +
      ")";
    ctx.closePath();
    ctx.stroke();
  }
}

function drawPlayer() {
  // ctx.beginPath();
  // ctx.arc(playerPosition.x, playerPosition.y, playerRadius, 0, Math.PI * 2);
  // ctx.fillStyle = "rgba(106, 77, 135)";

  var depthFront = player.z + player.radius;
  var depthTail = player.z - player.radius;

  var vertices = [
    {
      x: canvas.width / 2 + (player.x / depthFront) * viewScale,
      y: canvas.height / 2 + (player.y / depthFront) * viewScale
    },
    {
      x: canvas.width / 2 + ((player.x + 0.1) / depthTail) * viewScale,
      y: canvas.height / 2 + (player.y / depthTail) * viewScale
    },
    {
      x: canvas.width / 2 + ((player.x - 0.1) / depthTail) * viewScale,
      y: canvas.height / 2 + (player.y / depthTail) * viewScale
    }
  ];
  var fog = calculateFogAmount(player.z);
  ctx.beginPath();
  // ctx.lineWidth = "5";
  ctx.fillStyle = "rgba(0, 0, 0," + fog + ")";
  ctx.moveTo(vertices[0].x, vertices[0].y);
  ctx.lineTo(vertices[1].x, vertices[1].y);
  ctx.lineTo(vertices[2].x, vertices[2].y);
  ctx.closePath();
  ctx.fill();

  ctx.strokeStyle = "rgba(255, 0, 0," + fog + ")";
  ctx.beginPath();
  ctx.arc(
    canvas.width / 2 + (player.x / player.z) * viewScale,
    canvas.height / 2 + (player.y / player.z) * viewScale,
    (player.radius / player.z) * viewScale,
    0,
    2 * Math.PI
  );
  ctx.closePath();
  ctx.stroke();
}

function drawScore() {
  ctx.font = "20px monospace";
  if (score > 999999) {
    score = 999999;
  }

  ctx.fillStyle =
    "rgba(" +
    config.scoreTextColor.r +
    "," +
    config.scoreTextColor.g +
    "," +
    config.scoreTextColor.b +
    ",0.7)";

  ctx.fillText(
    "HI " + ("000000" + highscore.toFixed(0)).slice(-6),
    canvas.width - 200,
    canvas.height - 20
  );

  ctx.fillStyle =
    "rgba(" +
    config.scoreTextColor.r +
    "," +
    config.scoreTextColor.g +
    "," +
    config.scoreTextColor.b +
    ",1)";

  ctx.fillText(
    ("000000" + score.toFixed(0)).slice(-6),
    canvas.width - 90,
    canvas.height - 20
  );
}

function overlapsWithRectAndCircle(rect, circle) {
  var x1 = rect.x - rect.width / 2;
  var y1 = rect.y - rect.height / 2;
  var x2 = rect.x + rect.width / 2;
  var y2 = rect.y + rect.height / 2;
  var xc = circle.x;
  var yc = circle.y;
  var r = circle.radius;

  return (
    (xc > x1 && xc < x2 && yc > y1 - r && yc < y2 + r) ||
    (xc > x1 - r && xc < x2 + r && yc > y1 && yc < y2) ||
    (x1 - xc) * (x1 - xc) + (y1 - yc) * (y1 - yc) < r * r ||
    (x2 - xc) * (x2 - xc) + (y1 - yc) * (y1 - yc) < r * r ||
    (x2 - xc) * (x2 - xc) + (y2 - yc) * (y2 - yc) < r * r ||
    (x1 - xc) * (x1 - xc) + (y2 - yc) * (y2 - yc) < r * r
  );
}

function containsWithRectAndCircle(rect, circle) {
  var width = rect.width - 4 * circle.radius;
  var height = rect.height - 4 * circle.radius;

  return (
    width > 0 &&
    height > 0 &&
    overlapsWithRectAndCircle(
      {
        x: rect.x,
        y: rect.y,
        width: width,
        height: height
      },
      circle
    )
  );
}

function collisionDetection() {
  for (var i = bullets.length - 1; i >= 0; i--) {
    var bullet = bullets[i];
    if (!bullet.enabled) continue;

    for (var j = 0; j < walls.length; j++) {
      var wall = walls[j];
      var obstacle = wall.obstacle;
      if (!obstacle.enabled) continue;

      if (
        (Math.abs(bullet.z - wall.z) < bullet.radius ||
          (bullet.z > wall.z + wallSpeed &&
            bullet.z - bulletSpeed < wall.z + wallSpeed)) &&
        overlapsWithRectAndCircle(obstacle, bullet)
      ) {
        obstacle.hp -= 0.15;
        obstacle.scale = 1.1;
        // score += 1;
        if (obstacle.hp <= 0) {
          obstacle.enabled = false;
          score += 5 * scoreMultiplier;
        }
        bullet.enabled = false;
      }
    }
  }
  // alert(player.y);
  for (var i = 0; i < walls.length; i++) {
    var wall = walls[i];
    var obstacle = wall.obstacle;
    if (!obstacle.enabled) continue;

    if (
      (Math.abs(player.z - wall.z) < player.radius ||
        (player.z > wall.z && wall.z + wallSpeed > player.z)) &&
      containsWithRectAndCircle(obstacle, player)
    ) {
      gameover = true;
      obstacle.type = "collided";
    }
  }
}

function updateWalls() {
  for (var i = walls.length - 1; i >= 0; i--) {
    var wall = walls[i];

    wall.z -= wallSpeed;

    if (wall.obstacle.enabled) {
      wall.obstacle.scale -= 0.01;
      if (wall.obstacle.scale < 1) wall.obstacle.scale = 1;
    }

    if (wall.z <= -2) {
      walls.splice(0, i + 1);
      break;
    }
  }
}

function updateBullets() {
  for (var i = bullets.length - 1; i >= 0; i--) {
    var bullet = bullets[i];
    bullet.z += bulletSpeed;
    if (bullet.z > renderDepth / 2) {
      bullets.splice(0, i + 1);
      break;
    }
  }
}

// score = 10000;
function levelControl() {
  // wallSpeed = lerp(0.02, 0.15, score / 10000);
  // bulletCooldown = lerp(10, 3, score / 10000);
  wallSpeed = lerp(0.02, 0.12, score / 10000);
  bulletCooldown = lerp(10, 4, score / 10000);
  scoreMultiplier = lerp(1, 1000, score / 100000);
  score += (1 / 60) * scoreMultiplier;
}

function idleUpdate() {
  wallSpeed = 0.02;
  generateWall(false);
  updateWalls();
  drawWalls();
}

function gameplayUpdate() {
  gameover = false;
  if (gameplayStartLoopCounter++ < 60) {
    getSelection().removeAllRanges();
  }
  levelControl();

  generateWall(true);
  emitBullet();

  updateBullets();
  updateWalls();
  collisionDetection();

  drawWalls();
  drawPlayer();
  drawBullets();
  drawScore();
  if (gameover) beginGameover();
}

function gameoverUpdate() {
  drawWalls();
  drawPlayer();
  drawBullets();
  drawScore();
}

function draw() {
  ctx.clearRect(0, 0, canvas.width, canvas.height);

  if (canvas.height < canvas.width) viewScale = canvas.height / 2;
  else viewScale = canvas.width / 2;

  switch (gameState) {
    default:
    case GameState.Idle:
      idleUpdate();
      break;
    case GameState.Gameover:
      gameoverUpdate();
      break;

    case GameState.Gameplay:
      gameplayUpdate();
      break;
  }

  requestAnimationFrame(draw);
}

//リサイズ時
window.onresize = function() {
  //再描画のため必ずCanvasの描画領域をクリアする
  ctx.clearRect(0, 0, canvas.width, canvas.height);
  canvas.width = container.clientWidth;
  canvas.height = container.clientHeight;
};

function startGame() {
  beginIdle();
  draw();
}
