; (function () {
  "use strict"



  const FileManagerClient = (serviceUrl, token) => {
    
    function sendRequest(data) {
      const promise = new Promise((resolve, reject) => {
        const form = new FormData()

        for (let key in data) {
          form.append(key, data[key])
        }

        const request = new XMLHttpRequest()
        request.open("POST", serviceUrl, true)
  
        request.onload = function (e) {
          // validate response
          if (request.status != 200) {
            reject(new Error("Lost Server"))
            return
          }
  
          let parsedResponse = null
          try {
            parsedResponse = JSON.parse(request.response)
          }
          catch (error) {
            reject(new Error(`Server Error.\n${request.response}`))
            return
          }
          
          if (parsedResponse == null) {
            reject(new Error(`No Response from Server`))
            return
          }
  
          if (parsedResponse.error != null) {
            reject(new Error(parsedResponse.error))
            return
          }
  
          // All ok!
          resolve(parsedResponse)
          return
        }
  
        request.onerror = function (e) {
          console.log("#####", e)
          reject(new Error(`Network Error`))
          return
        }

        request.send(form)
       })
       return promise
    }

    function uploadFile(directoryPath, file) {
      return sendRequest({
        cmd: "UploadFile",
        upFile: file,
        directoryPath: directoryPath,
        token: token
      })
    }

    function removeFile(path) {
      return sendRequest({
        cmd: "DeleteFile",
        filePath: path,
        token: token
      })
    }

    function removeDirectory(path) {
      return sendRequest({
        cmd: "DeleteDirectory",
        directoryPath: path,
        token: token
      })
    }

    function createFile(path) {
      return sendRequest({
        cmd: "CreateNewFile",
        filePath: path,
        token: token
      })
    }

    function createDirectory(path) {
      return sendRequest({
        cmd: "CreateNewDirectory",
        directoryPath: path,
        token: token
      })
    }

    function rename(from, to) {
      return sendRequest({
        cmd: "Rename",
        oldName: from,
        newName: to,
        token: token
      })
    }

    return {
      createFile: createFile,
      createDirectory: createDirectory,
      removeFile: removeFile,
      removeDirectory: removeDirectory,
      uploadFile: uploadFile,
      rename: rename
    }
  }

  window.FileManagerClient = FileManagerClient
})()