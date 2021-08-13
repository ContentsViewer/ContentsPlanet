;(function () {
  "use strict"

  var TC = {}

  TC.onChangeThemeCallbacks = []

  TC.getCookieData = function () {
    //データを1つずつに分ける
    var r = document.cookie.split(";")
    var data = {}
    r.forEach(function (value) {
      //cookie名と値に分ける
      var content = value.split("=")
      data[content[0].trim()] = typeof content[1] == "string" ? content[1].trim() : content[1]
    })
    return data
  }

  TC.getCurrentTheme = function () {
    var cookieData = TC.getCookieData()

    if (!cookieData.theme || typeof cookieData.theme != "string") {
      return false
    }
    return cookieData.theme
  }

  TC.changeTheme = function () {
    var currentTheme = TC.getCurrentTheme()

    TC.onChangeThemeCallbacks.forEach(function (value) {
      value()
    })

    if (currentTheme === false) {
      document.documentElement.removeAttribute("theme")
      return
    }
    document.documentElement.setAttribute("theme", currentTheme)
  }

  TC.setTheme = function (themeName) {
    document.cookie = "theme=" + themeName + "; path=/"
  }

  TC.clearTheme = function () {
    document.cookie = "theme=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT"
  }

  TC.changeTheme()

  window.ThemeChanger = TC
})()
