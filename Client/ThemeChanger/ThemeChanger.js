function changeTheme() {
  var currentTheme = getCurrentTheme();

  if (currentTheme === false) {
    document.documentElement.removeAttribute("theme");
    return;
  }
  document.documentElement.setAttribute("theme", currentTheme);
  // console.log(getCookieData());
}

function getCookieData() {
  // console.log(document.cookie);
  //データを1つずつに分ける
  var r = document.cookie.split(";");
  var data = {};
  r.forEach(function(value) {
    //cookie名と値に分ける
    var content = value.split("=");
    data[content[0].trim()] =
      typeof content[1] == "string" ? content[1].trim() : content[1];
  });
  return data;
}

function setTheme(themeName) {
  document.cookie = "theme=" + themeName + "; path=/";
}

function clearTheme() {
  document.cookie = "theme=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT";
}

function getCurrentTheme() {
  var cookieData = getCookieData();

  if (!cookieData.theme || typeof cookieData.theme != "string") {
    return false;
  }
  return cookieData.theme;
}

// saveTheme("dark");
changeTheme();
