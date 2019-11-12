//
// ファイル(フォルダ)要素に関係するデータ保管とそれに対する操作
// fileManager がなくても, 要素として存在できるように
//
class FileElement {
  constructor(isFolder, fileManager, path, options) {
    if (options == null) {
      options = {};
    }

    var defaultOptions = FileElement.DefaultOptions();
    for (var key in defaultOptions) {
      if (!(key in options)) {
        options[key] = defaultOptions[key];
        //alert(key);
      }
    }
    this.options = options;

    this.fileManager = fileManager;
    this.path = path;
    this.isFolder = isFolder;

    this.directoryName = FileManager.GetDirectoryName(path);
    this.baseName = FileManager.GetBaseName(path);

    // alert(this.directoryName);
    // alert(this.baseName);

    this.SetupElement();
  }

  SetupElement() {
    this.element = document.createElement("li");
    this.element.setAttribute("class", this.isFolder ? "folder" : "file");
    var wrapper = document.createElement("div");
    wrapper.setAttribute("class", "wrapper");

    if (!this.options.hideInputField) {
      this.inputField = document.createElement("input");
      if (this.isFolder) {
        this.inputField.value = this.baseName;
      } else {
        if (this.options.hideExtention) {
          this.inputField.value = FileManager.RemoveExtention(this.baseName);
        } else {
          this.inputField.value = this.baseName;
        }
      }

      wrapper.appendChild(this.inputField);
    }

    if (!this.options.hideDropField) {
      this.dropField = document.createElement("div");
      this.dropField.setAttribute("class", "drop-field");
      this.dropField.textContent = "ファイルをドラック&ドロップ";
      this.dropField.addEventListener("dragover", function(e) {
        e.preventDefault();
      });
      this.dropField.addEventListener(
        "drop",
        this.options.dropCallbackFunction
      );
      this.dropField.fileElement = this;
      wrapper.appendChild(this.dropField);
    }

    if (!this.options.hideOpenButton) {
      this.openButton = this.CreateButton(
        "→",
        "open",
        this.options.openCallbackFunction
      );
      wrapper.appendChild(this.openButton);
    }

    if (!this.options.hideAddButton) {
      wrapper.appendChild(
        this.CreateButton("+", "add", this.options.addCallbackFunction)
      );
    }

    if (
      !this.options.hidePreview &&
      !this.isFolder &&
      FileManager.IsImageFile(this.path)
    ) {
      var image = document.createElement("img");
      var src = this.path;
      if (this.options.path2uriCallbackFunction) {
        src = this.options.path2uriCallbackFunction(this.path);
      }
      image.setAttribute("src", src);
      wrapper.appendChild(image);
    }

    if (!this.options.hidePathLabel) {
      var pathLabel = document.createElement("span");
      pathLabel.setAttribute("class", "path-label");
      pathLabel.addEventListener("click", FileElement.CopyPathText);
      pathLabel.textContent = this.path;
      wrapper.appendChild(pathLabel);
      pathLabel.fileElement = this;
    }

    if (!this.options.hideRenameButton) {
      wrapper.appendChild(
        this.CreateButton(
          "Rename",
          "rename",
          this.options.renameCallbackFunction
        )
      );
    }

    if (!this.options.hideDeleteButton) {
      wrapper.appendChild(
        this.CreateButton("x", "delete", this.options.deleteCallbackFunction)
      );
    }
    this.element.appendChild(wrapper);
    this.wrapper = wrapper;
    this.element.fileElement = this;
  }

  CreateButton(content, className, listener) {
    var button = document.createElement("button");
    button.fileElement = this;
    button.setAttribute("class", className);
    button.addEventListener("click", listener, false);
    button.textContent = content;

    return button;
  }

  RemoveOpenButton() {
    if (this.openButton != null) {
      this.wrapper.removeChild(this.openButton);
      this.openButton = null;
    }
  }

  static CopyText(string) {
    var temp = document.createElement("div");

    temp.appendChild(document.createElement("pre")).textContent = string;

    var s = temp.style;
    s.position = "fixed";
    s.left = "-100%";

    document.body.appendChild(temp);
    document.getSelection().selectAllChildren(temp);

    var result = document.execCommand("copy");

    document.body.removeChild(temp);
    // true なら実行できている falseなら失敗か対応していないか
    return result;
  }

  static CopyPathText() {
    var fileElement = this.fileElement;
    var text = fileElement.path;

    if (fileElement.options.copyPathTextCallbackFunction != null) {
      text = fileElement.options.copyPathTextCallbackFunction(fileElement);
    }

    if (FileElement.CopyText(text)) {
      alert("パスをコピーしました. \n" + text);
    }

    //     var selection = window.getSelection();

    //     var range = document.createRange();

    //     range.selectNodeContents(this);

    //     // 一旦、selectionオブジェクトの持つ rangeオブジェクトを削除します.
    //     selection.removeAllRanges();

    //     // 改めて先程生成した rangeオブジェクトを selectionオブジェクトに追加します.
    //     selection.addRange(range);

    //     // console.log('選択された文字列: ', selection.toString());

    //     // クリップボードにコピーします。
    //     var succeeded = document.execCommand('copy');
    //     if (succeeded) {
    //         alert('パスをコピーしました. \n' + selection.toString());
    //         // コピーに成功した場合の処理です。
    //         // console.log('コピーが成功しました！');
    //     } else {
    //         // コピーに失敗した場合の処理です。
    //         // console.log('コピーが失敗しました!');
    //     }

    //     // selectionオブジェクトの持つrangeオブジェクトを全て削除しておきます。
    //     selection.removeAllRanges();
  }

  static DefaultOptions() {
    return {
      hideInputField: false,
      hidePathLabel: false,
      hideOpenButton: false,
      hideDeleteButton: false,
      hideRenameButton: false,
      hideAddButton: true,
      hideExtention: false,
      hidePreview: false,
      hideDropField: true,
      openCallbackFunction: FileManager.Open,
      renameCallbackFunction: FileManager.Rename,
      deleteCallbackFunction: FileManager.Delete,
      addCallbackFunction: FileManager.Add,
      dropCallbackFunction: FileManager.Drop,
      copyPathTextCallbackFunction: null,
      path2uriCallbackFunction: null
    };
  }
}

//
// ファイルデータ構造, ファイル操作を扱う
//
class FileManager {
  constructor(
    fileTreeAreaElement,
    rootFolderPath,
    token,
    openFileCallbackFunction,
    path2uriCallbackFunction,
    readonly,
    copyPathTextCallbackFunction,
    sendRequestCallbackFunction,
    receiveResponseCallbackFunction
  ) {
    this.fileTreeAreaElement = fileTreeAreaElement;
    this.rootFolderPath = rootFolderPath;
    this.hideExtention = false;
    this.token = token;
    this.readonly = readonly;
    this.openFileCallbackFunction = openFileCallbackFunction;
    this.path2uriCallbackFunction = path2uriCallbackFunction;
    this.copyPathTextCallbackFunction = copyPathTextCallbackFunction;
    this.sendRequestCallbackFunction = sendRequestCallbackFunction;
    this.receiveResponseCallbackFunction = receiveResponseCallbackFunction;
    this.fileMap = {};

    this.rootFolderElement = this.CreateFileElement(
      true,
      this.rootFolderPath,
      false
    );

    this.rootListElement = FileManager.CreateListElement();
    this.fileTreeAreaElement.appendChild(this.rootListElement);

    this.rootListElement.appendChild(this.rootFolderElement.element);
    this.fileExtentionPattern = "*";
    // this.fileExtentionPattern = "{";
    // for (var i = 0; i < fileExtentions.length; i++) {
    //     this.fileExtentionPattern += "*" + fileExtentions[i] + ((i == fileExtentions.length - 1) ? "" : ",");
    // }
    // this.fileExtentionPattern += "}";
  }

  CreateFileElement(isFolder, path, isEditable) {
    var newElement = new FileElement(isFolder, this, path, {
      hideExtention: this.hideExtention,
      hideRenameButton: !isEditable,
      hideDeleteButton: !isEditable,
      path2uriCallbackFunction: this.path2uriCallbackFunction,
      copyPathTextCallbackFunction: this.copyPathTextCallbackFunction
    });

    this.fileMap[path] = newElement;
    return newElement;
  }

  RemoveFileElement(fileElement) {
    // 古いパスキーを削除
    delete this.fileMap[fileElement.path];

    fileElement.element.parentNode.removeChild(fileElement.element);
  }

  CreateNewFileElement(isFolder, path) {
    return new FileElement(isFolder, this, path, {
      hideExtention: this.hideExtention,
      hideOpenButton: true,
      hideAddButton: false,
      hideRenameButton: true,
      hideDeleteButton: true,
      hidePathLabel: false,
      hidePreview: true,
      path2uriCallbackFunction: this.path2uriCallbackFunction,
      copyPathTextCallbackFunction: this.copyPathTextCallbackFunction
    });
  }

  CreateNewDropFileElement(path) {
    return new FileElement(false, this, path, {
      hideInputField: true,
      hidePathLabel: true,
      hideOpenButton: true,
      hideDeleteButton: true,
      hideRenameButton: true,
      hideAddButton: true,
      hideExtention: this.hideExtention,
      hidePreview: true,
      hideDropField: false,
      path2uriCallbackFunction: this.path2uriCallbackFunction,
      copyPathTextCallbackFunction: this.copyPathTextCallbackFunction
    });
  }

  static RemoveExtention(path) {
    return path.substr(0, path.lastIndexOf("."));
  }

  static GetExtention(path) {
    return path.slice(path.lastIndexOf("."));
  }

  static GetDirectoryName(path) {
    return path.substr(0, path.lastIndexOf("/"));
  }

  static GetBaseName(path) {
    return path.slice(path.lastIndexOf("/") + 1);
  }

  static IsImageFile(path) {
    var extention = FileManager.GetExtention(path);

    return extention in FileManager.ImageFileExtentions;
  }

  static CreateListElement() {
    var listElement = document.createElement("ul");
    listElement.setAttribute("class", "file-tree");
    return listElement;
  }

  static CreateForm(data) {
    var form = new FormData();

    for (var key in data) {
      form.append(key, data[key]);
    }

    return form;
  }

  static CreateRequest(fileElement) {
    var request = new XMLHttpRequest();
    request.open("POST", FileManager.ManagementServiceName, true);
    //request.responseType = "json";
    request.fileElement = fileElement;

    return request;
  }

  static ValidateResponse(request) {
    if (request.status != 200) {
      alert("Lost server.");
      return false;
    }

    var parsedResponse = null;

    try {
      parsedResponse = JSON.parse(request.response);
    } catch (error) {
      alert("Fatal Error in the server.\n" + request.response);
      return false;
    }

    request.parsedResponse = parsedResponse;

    if (parsedResponse == null) {
      alert("No response from the server.");
      return false;
    }

    if (parsedResponse.isOk != null && !parsedResponse.isOk) {
      if (parsedResponse.error != null) {
        alert(parsedResponse.error);
      }
      return false;
    }

    return true;
  }

  static ValidateInputField(inputField) {
    inputField.value = inputField.value.trim();

    if (inputField.value == "") {
      return false;
    }

    return true;
  }

  static Open() {
    var fileElement = this.fileElement;
    var fileManager = this.fileElement.fileManager;

    if (!fileElement.isFolder) {
      fileManager.openFileCallbackFunction(fileElement.path);
      return;
    }

    fileElement.listElement = FileManager.CreateListElement();
    fileElement.element.appendChild(fileElement.listElement);

    if (!fileManager.readonly) {
      fileElement.listElement.appendChild(
        fileManager.CreateNewDropFileElement(fileElement.path + "/").element
      );

      fileElement.listElement.appendChild(
        fileManager.CreateNewFileElement(false, fileElement.path + "/").element
      );

      fileElement.listElement.appendChild(
        fileManager.CreateNewFileElement(true, fileElement.path + "/").element
      );
    }

    fileElement.RemoveOpenButton();
    //alert(fileManager.fileExtentionPattern);

    var form = FileManager.CreateForm({
      cmd: "GetFileList",
      directoryPath: fileElement.path,
      token: fileManager.token,
      pattern: fileManager.fileExtentionPattern
    });

    var request = FileManager.CreateRequest(fileElement);
    request.onload = function(e) {
      FileManager.CallReceiveResponseCallbackFunction(this);

      if (!FileManager.ValidateResponse(this)) {
        return;
      }

      var fileList = this.parsedResponse.fileList;
      //alert(xhr.fileElement.path);
      //alert(fileList);
      if (fileList.length <= 0) {
        return;
      }

      for (var i = 0; i < fileList.length; i++) {
        var file = this.fileElement.fileManager.CreateFileElement(
          false,
          fileList[i],
          !this.fileElement.fileManager.readonly
        );
        this.fileElement.listElement.appendChild(file.element);
      }
    };
    //送信
    request.send(form);
    FileManager.CallSendRequestCallbackFunction(request);

    form = FileManager.CreateForm({
      cmd: "GetDirectoryList",
      directoryPath: fileElement.path,
      token: fileManager.token
    });

    request = FileManager.CreateRequest(fileElement);

    request.onload = function(e) {
      FileManager.CallReceiveResponseCallbackFunction(this);

      if (!FileManager.ValidateResponse(this)) {
        return;
      }
      var folderList = this.parsedResponse.directoryList;

      if (folderList.length <= 0) {
        return;
      }

      for (var i = 0; i < folderList.length; i++) {
        var folder = this.fileElement.fileManager.CreateFileElement(
          true,
          folderList[i],
          !this.fileElement.fileManager.readonly
        );
        this.fileElement.listElement.appendChild(folder.element);
      }
    };

    //送信
    request.send(form);
    FileManager.CallSendRequestCallbackFunction(request);
  }

  static Rename() {
    var fileElement = this.fileElement;
    var fileManager = this.fileElement.fileManager;

    var oldName = fileElement.path;
    var newName = "";

    if (!FileManager.ValidateInputField(fileElement.inputField)) {
      alert("Wrong name!");
      return;
    }

    if (fileElement.isFolder) {
      newName = fileElement.directoryName + "/" + fileElement.inputField.value;
    } else {
      newName = fileElement.directoryName + "/" + fileElement.inputField.value;

      if (fileElement.options.hideExtention) {
        newName += FileManager.GetExtention(fileElement.baseName);
      }
    }

    alert(oldName + " -> " + newName);

    if (!window.confirm("Are you sure?")) {
      return;
    }

    var form = FileManager.CreateForm({
      cmd: "Rename",
      oldName: oldName,
      newName: newName,
      token: fileManager.token
    });

    var request = FileManager.CreateRequest(fileElement);

    request.onload = function(e) {
      FileManager.CallReceiveResponseCallbackFunction(this);

      if (!FileManager.ValidateResponse(this)) {
        alert("Cannot rename");
        return;
      }

      var newName = this.parsedResponse.newName;

      this.fileElement.fileManager.RemoveFileElement(this.fileElement);

      if (
        FileManager.GetDirectoryName(newName) in
        this.fileElement.fileManager.fileMap
      ) {
        var newDirectory = this.fileElement.fileManager.fileMap[
          FileManager.GetDirectoryName(newName)
        ];

        if (newDirectory.listElement != null) {
          var file = this.fileElement.fileManager.CreateFileElement(
            this.fileElement.isFolder,
            newName,
            true
          );
          newDirectory.listElement.appendChild(file.element);
        }
      }
    };

    //送信
    request.send(form);
    FileManager.CallSendRequestCallbackFunction(request);
    //alert("rename");
  }

  static Delete() {
    var fileElement = this.fileElement;
    var fileManager = this.fileElement.fileManager;

    var filePathToDelete = fileElement.path;
    alert("Delete " + filePathToDelete);
    if (!window.confirm("Are you sure?")) {
      return;
    }

    var form = null;

    if (fileElement.isFolder) {
      form = FileManager.CreateForm({
        cmd: "DeleteDirectory",
        directoryPath: filePathToDelete,
        token: fileManager.token
      });
    } else {
      form = FileManager.CreateForm({
        cmd: "DeleteFile",
        filePath: filePathToDelete,
        token: fileManager.token
      });
    }

    var request = FileManager.CreateRequest(fileElement);
    request.onload = function(e) {
      FileManager.CallReceiveResponseCallbackFunction(this);

      if (!FileManager.ValidateResponse(this)) {
        alert("Cannot delete");
        return;
      }

      this.fileElement.fileManager.RemoveFileElement(this.fileElement);
    };

    //送信
    request.send(form);
    FileManager.CallSendRequestCallbackFunction(request);

    //alert("delete");
  }

  static Add() {
    var fileElement = this.fileElement;
    var fileManager = this.fileElement.fileManager;

    if (!FileManager.ValidateInputField(fileElement.inputField)) {
      alert("Wrong name!");
      return;
    }

    var newName = "";

    if (
      fileElement.isFolder ||
      (!fileElement.isFolder && !fileElement.options.hideExtention)
    ) {
      newName = fileElement.directoryName + "/" + fileElement.inputField.value;
    } else {
      newName =
        fileElement.directoryName +
        "/" +
        fileElement.inputField.value +
        FileManager.GetExtention(fileElement.path);
    }

    alert("Create " + newName);
    if (!window.confirm("Are you sure?")) {
      return;
    }

    var form = null;

    if (fileElement.isFolder) {
      form = FileManager.CreateForm({
        cmd: "CreateNewDirectory",
        directoryPath: newName,
        token: fileManager.token
      });
    } else {
      form = FileManager.CreateForm({
        cmd: "CreateNewFile",
        filePath: newName,
        token: fileManager.token
      });
    }

    var request = FileManager.CreateRequest(fileElement);

    request.onload = function(e) {
      FileManager.CallReceiveResponseCallbackFunction(this);

      if (!FileManager.ValidateResponse(request)) {
        alert("Cannot create new file or directory.");

        return;
      }

      var filePath = this.fileElement.isFolder
        ? this.parsedResponse.directoryPath
        : this.parsedResponse.filePath;

      var file = this.fileElement.fileManager.CreateFileElement(
        this.fileElement.isFolder,
        filePath,
        true
      );
      fileElement.element.parentNode.appendChild(file.element);
    };

    //送信
    request.send(form);
    FileManager.CallSendRequestCallbackFunction(request);
  }

  static Drop(e) {
    e.preventDefault();
    var files = e.dataTransfer.files;
    for (var i = 0; i < files.length; i++) {
      FileManager.UploadFile(files[i], this.fileElement);
    }
  }

  static UploadFile(file, fileElement) {
    var form = FileManager.CreateForm({
      cmd: "UploadFile",
      upFile: file,
      directoryPath: fileElement.directoryName,
      token: fileElement.fileManager.token
    });

    var request = FileManager.CreateRequest(fileElement);
    request.onload = function(e) {
      FileManager.CallReceiveResponseCallbackFunction(this);

      if (!FileManager.ValidateResponse(request)) {
        alert("Failed to upload.");
        return;
      }

      var file = this.fileElement.fileManager.CreateFileElement(
        this.fileElement.isFolder,
        this.parsedResponse.filePath,
        true
      );
      fileElement.element.parentNode.appendChild(file.element);
    };

    request.send(form);
    FileManager.CallSendRequestCallbackFunction(request);
  }

  static CallSendRequestCallbackFunction(request) {
    var callback = request.fileElement.fileManager.sendRequestCallbackFunction;
    if (callback != null) {
      callback(request);
    }
  }

  static CallReceiveResponseCallbackFunction(request) {
    var callback =
      request.fileElement.fileManager.receiveResponseCallbackFunction;
    if (callback != null) {
      callback(request);
    }
  }
}

FileManager.ManagementServiceName = "Service/file-management-service.php";
FileManager.ImageFileExtentions = {
  ".png": null,
  ".jpg": null,
  ".gif": null,
  ".bmp": null
};
