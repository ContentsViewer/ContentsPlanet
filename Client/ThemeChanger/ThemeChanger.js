var ThemeChanger = {};

ThemeChanger.onChangeThemeCallbacks = [];

ThemeChanger.changeTheme = function() {
  var currentTheme = this.getCurrentTheme();

  this.onChangeThemeCallbacks.forEach(function(value) {
    value();
  });

  if (currentTheme === false) {
    document.documentElement.removeAttribute("theme");
    return;
  }
  document.documentElement.setAttribute("theme", currentTheme);
  // console.log(getCookieData());
};

ThemeChanger.getCookieData = function() {
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
};

ThemeChanger.setTheme = function(themeName) {
  document.cookie = "theme=" + themeName + "; path=/";
};

ThemeChanger.clearTheme = function() {
  document.cookie = "theme=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT";
};

ThemeChanger.getCurrentTheme = function() {
  var cookieData = this.getCookieData();

  if (!cookieData.theme || typeof cookieData.theme != "string") {
    return false;
  }
  return cookieData.theme;
};

// saveTheme("dark");
ThemeChanger.changeTheme();
