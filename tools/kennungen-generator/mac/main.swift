// OMUN Kennungen – Mac app.
//
// A window that shows ../app/index.html and does for it what a web page may
// not do itself: read and write the list of handed-out codes (code + name) in
// a file on the desktop, keep the website key, save files and print.
//
// Build: ./build.sh   (needs the Xcode command line tools: xcode-select --install)

import Cocoa
import WebKit

// MARK: - App

final class AppDelegate: NSObject, NSApplicationDelegate, WKScriptMessageHandlerWithReply, WKNavigationDelegate {
    var window: NSWindow!
    var webView: WKWebView!

    func applicationDidFinishLaunching(_ notification: Notification) {
        let config = WKWebViewConfiguration()
        config.userContentController.addScriptMessageHandler(self, contentWorld: .page, name: "omun")
        webView = WKWebView(frame: .zero, configuration: config)
        webView.navigationDelegate = self

        window = NSWindow(contentRect: NSRect(x: 0, y: 0, width: 980, height: 860),
                          styleMask: [.titled, .closable, .miniaturizable, .resizable], backing: .buffered, defer: false)
        window.title = "OMUN Kennungen"
        window.contentView = webView
        window.center()
        window.setFrameAutosaveName("main")
        window.makeKeyAndOrderFront(nil)
        buildMenu()

        guard let page = Bundle.main.url(forResource: "index", withExtension: "html") else {
            fatalError("index.html is missing in the app bundle")
        }
        webView.loadFileURL(page, allowingReadAccessTo: page.deletingLastPathComponent())
        NSApp.activate(ignoringOtherApps: true)
    }

    func applicationShouldTerminateAfterLastWindowClosed(_ sender: NSApplication) -> Bool { true }

    #if SELFTEST
    // Test build only (swiftc -D SELFTEST): drives the page once and writes what happened to a file.
    func webView(_ webView: WKWebView, didFinish navigation: WKNavigation!) {
        let env = ProcessInfo.processInfo.environment
        let js = (try? String(contentsOfFile: env["OMUN_SELFTEST_JS"] ?? "", encoding: .utf8)) ?? ""
        webView.callAsyncJavaScript(js, arguments: [:], in: nil, in: .page) { result in
            let text: String
            switch result { case .success(let v): text = "\(v)"; case .failure(let e): text = "ERROR \(e)" }
            try? text.write(toFile: env["OMUN_SELFTEST_OUT"] ?? "/dev/null", atomically: true, encoding: .utf8)
            NSApp.terminate(nil)
        }
    }
    #endif

    /// Links (there are none on purpose) open in the browser, never inside the app.
    func webView(_ webView: WKWebView, decidePolicyFor action: WKNavigationAction, decisionHandler: @escaping (WKNavigationActionPolicy) -> Void) {
        if action.navigationType == .linkActivated, let url = action.request.url {
            NSWorkspace.shared.open(url)
            decisionHandler(.cancel)
        } else {
            decisionHandler(.allow)
        }
    }

    func buildMenu() {
        let main = NSMenu()
        let appItem = NSMenuItem(); main.addItem(appItem)
        let appMenu = NSMenu()
        appMenu.addItem(withTitle: "OMUN Kennungen ausblenden", action: #selector(NSApplication.hide(_:)), keyEquivalent: "h")
        appMenu.addItem(.separator())
        appMenu.addItem(withTitle: "OMUN Kennungen beenden", action: #selector(NSApplication.terminate(_:)), keyEquivalent: "q")
        appItem.submenu = appMenu
        // Without an Edit menu, copy and paste do not work in the text fields.
        let editItem = NSMenuItem(); main.addItem(editItem)
        let edit = NSMenu(title: "Bearbeiten")
        edit.addItem(withTitle: "Widerrufen", action: Selector(("undo:")), keyEquivalent: "z")
        edit.addItem(withTitle: "Wiederholen", action: Selector(("redo:")), keyEquivalent: "Z")
        edit.addItem(.separator())
        edit.addItem(withTitle: "Ausschneiden", action: #selector(NSText.cut(_:)), keyEquivalent: "x")
        edit.addItem(withTitle: "Kopieren", action: #selector(NSText.copy(_:)), keyEquivalent: "c")
        edit.addItem(withTitle: "Einsetzen", action: #selector(NSText.paste(_:)), keyEquivalent: "v")
        edit.addItem(withTitle: "Alles auswählen", action: #selector(NSText.selectAll(_:)), keyEquivalent: "a")
        editItem.submenu = edit
        NSApp.mainMenu = main
    }

    // The page asks, the app answers (see backend() in index.html).
    func userContentController(_ controller: WKUserContentController, didReceive message: WKScriptMessage,
                               replyHandler: @escaping (Any?, String?) -> Void) {
        // Only our own page may talk to the app.
        guard message.frameInfo.isMainFrame, message.frameInfo.request.url?.isFileURL == true,
              let body = message.body as? [String: Any], let action = body["action"] as? String else {
            return replyHandler(nil, "not allowed")
        }
        let data = body["data"] as? [String: Any] ?? [:]
        switch action {
        case "list":
            replyHandler(["file": listURL.path, "rows": readList()], nil)
        case "add":
            do {
                let added = try addToList(data["rows"] as? [[String: Any]] ?? [])
                replyHandler(["file": listURL.path, "added": added, "rows": readList()], nil)
            } catch {
                replyHandler(["error": "Die Datei konnte nicht geschrieben werden: \(listURL.path) (ist sie gerade in Excel oder Numbers geöffnet?)"], nil)
            }
        case "settings":
            let saved = (try? JSONSerialization.jsonObject(with: Data(contentsOf: settingsURL))) as? [String: Any] ?? [:]
            replyHandler(["site": saved["site"] as? String ?? "", "key": saved["key"] as? String ?? ""], nil)
        case "settings_set":
            let settings = ["site": data["site"] as? String ?? "", "key": data["key"] as? String ?? ""]
            if let json = try? JSONSerialization.data(withJSONObject: settings, options: [.prettyPrinted]) {
                try? json.write(to: settingsURL, options: [.atomic])
                try? FileManager.default.setAttributes([.posixPermissions: 0o600], ofItemAtPath: settingsURL.path)
            }
            replyHandler(["ok": true], nil)
        case "reveal":
            if FileManager.default.fileExists(atPath: listURL.path) {
                NSWorkspace.shared.activateFileViewerSelecting([listURL])
            } else {
                NSWorkspace.shared.open(listURL.deletingLastPathComponent())
            }
            replyHandler(["ok": true], nil)
        case "save":
            let panel = NSSavePanel()
            panel.nameFieldStringValue = data["name"] as? String ?? "kennungen.txt"
            panel.directoryURL = FileManager.default.homeDirectoryForCurrentUser.appendingPathComponent("Desktop")
            panel.beginSheetModal(for: window) { response in
                if response == .OK, let url = panel.url {
                    try? (data["text"] as? String ?? "").write(to: url, atomically: true, encoding: .utf8)
                }
                replyHandler(["ok": response == .OK], nil)
            }
        case "print":
            let info = NSPrintInfo.shared.copy() as! NSPrintInfo
            info.horizontalPagination = .fit
            info.verticalPagination = .automatic
            info.topMargin = 28; info.bottomMargin = 28; info.leftMargin = 28; info.rightMargin = 28
            let op = webView.printOperation(with: info)
            op.view?.frame = webView.bounds
            op.runModal(for: window, delegate: nil, didRun: nil, contextInfo: nil)
            replyHandler(["ok": true], nil)
        default:
            replyHandler(nil, "unknown action")
        }
    }
}

let app = NSApplication.shared
let delegate = AppDelegate()
app.delegate = delegate
app.setActivationPolicy(.regular)
app.run()
