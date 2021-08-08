(function () {
  const sh = window.SyntaxHighlighter

  if (!sh || !sh.src) {
    return
  }

  const { src } = sh

  const process = async () => {
    const loadScript = (src) => {
      return new Promise((resolve, reject) => {
        const script = document.createElement('script')
        script.src = src
        script.async = true
        script.onload = resolve
        script.onerror = reject
        document.head.appendChild(script)
      })
    }

    const loadStyle = (src) => {
      return new Promise((resolve, reject) => {
        const style = document.createElement('link')
        style.rel = 'preload'
        style.type = 'text/css'
        style.as = 'style'
        style.href = src
        style.onload = () => {
          style.rel = 'stylesheet'
          resolve()
        }
        style.onerror = reject
        document.head.appendChild(style)
      })
    }

    try {
      await loadScript(`${src}/scripts/shCore.js`)
      await loadScript(`${src}/scripts/shAutoloader.js`)

      SyntaxHighlighter.autoloader(
        `applescript           ${src}/scripts/shBrushAppleScript.js`,
        `actionscript3 as3     ${src}/scripts/shBrushAS3.js`,
        `bash shell            ${src}/scripts/shBrushBash.js`,
        `coldfusion cf         ${src}/scripts/shBrushColdFusion.js`,
        `cpp c                 ${src}/scripts/shBrushCpp.js`,
        `c# c-sharp csharp     ${src}/scripts/shBrushCSharp.js`,
        `css                   ${src}/scripts/shBrushCss.js`,
        `delphi pascal         ${src}/scripts/shBrushDelphi.js`,
        `diff patch pas        ${src}/scripts/shBrushDiff.js`,
        `erl erlang            ${src}/scripts/shBrushErlang.js`,
        `groovy                ${src}/scripts/shBrushGroovy.js`,
        `java                  ${src}/scripts/shBrushJava.js`,
        `jfx javafx            ${src}/scripts/shBrushJavaFX.js`,
        `js jscript javascript ${src}/scripts/shBrushJScript.js`,
        `perl pl               ${src}/scripts/shBrushPerl.js`,
        `php                   ${src}/scripts/shBrushPhp.js`,
        `text plain            ${src}/scripts/shBrushPlain.js`,
        `ps powershell         ${src}/scripts/shBrushPowerShell.js`,
        `py python             ${src}/scripts/shBrushPython.js`,
        `ruby rails ror rb     ${src}/scripts/shBrushRuby.js`,
        `sass scss             ${src}/scripts/shBrushSass.js`,
        `scala                 ${src}/scripts/shBrushScala.js`,
        `sql                   ${src}/scripts/shBrushSql.js`,
        `vb vbnet              ${src}/scripts/shBrushVb.js`,
        `xml xhtml xslt html   ${src}/scripts/shBrushXml.js`
      );
      SyntaxHighlighter.defaults['gutter'] = false;
      SyntaxHighlighter.all();

      await loadStyle(`${src}/styles/shCoreDefault.css`)
    }
    catch (error) {
      console.error(error)
    }
  }

  process()

})();