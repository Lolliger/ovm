// OMUN Kennungen – the list of handed-out codes on the desktop and where the settings are kept.

import Foundation

let listURL: URL = {
    if let custom = ProcessInfo.processInfo.environment["OMUN_KENNUNGEN_FILE"], !custom.isEmpty {
        return URL(fileURLWithPath: custom)
    }
    return FileManager.default.homeDirectoryForCurrentUser.appendingPathComponent("Desktop/OMUN-Kennungen.csv")
}()

let settingsURL: URL = {
    if let custom = ProcessInfo.processInfo.environment["OMUN_KENNUNGEN_SETTINGS"], !custom.isEmpty {
        return URL(fileURLWithPath: custom)
    }
    let dir = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask)[0].appendingPathComponent("OMUN Kennungen")
    try? FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
    return dir.appendingPathComponent("settings.json")
}()

// MARK: - The list (CSV with ";", like Excel in German writes it)

func normalizeCode(_ s: String) -> String {
    String(s.uppercased().unicodeScalars.filter { ("A"..."Z").contains($0) || ("0"..."9").contains($0) })
}

func codeOK(_ c: String) -> Bool {
    let allowed = Set("ABCDEFGHJKLMNPQRSTUVWXYZ23456789")
    return c.count == 8 && c.allSatisfy { allowed.contains($0) } && c.contains { $0.isNumber } && c.contains { $0.isLetter }
}

/// Splits CSV text into rows of cells (";" separated, quotes doubled inside quoted cells).
func parseCSV(_ text: String) -> [[String]] {
    var rows: [[String]] = [], row: [String] = [], cell = "", quoted = false
    var chars = Array(text.replacingOccurrences(of: "\r\n", with: "\n").replacingOccurrences(of: "\u{FEFF}", with: ""))[...]
    while let ch = chars.first {
        chars = chars.dropFirst()
        if quoted {
            if ch == "\"" {
                if chars.first == "\"" { cell.append("\""); chars = chars.dropFirst() } else { quoted = false }
            } else { cell.append(ch) }
        } else if ch == "\"" { quoted = true }
        else if ch == ";" { row.append(cell); cell = "" }
        else if ch == "\n" { row.append(cell); rows.append(row); row = []; cell = "" }
        else { cell.append(ch) }
    }
    if !cell.isEmpty || !row.isEmpty { row.append(cell); rows.append(row) }
    return rows
}

func csvCell(_ v: String) -> String {
    v.contains(where: { "\";\n".contains($0) }) ? "\"" + v.replacingOccurrences(of: "\"", with: "\"\"") + "\"" : v
}

func readList() -> [[String: String]] {
    guard let text = try? String(contentsOf: listURL, encoding: .utf8) else { return [] }
    return parseCSV(text).compactMap { cells in
        let code = normalizeCode(cells.first ?? "")
        guard codeOK(code) else { return nil }
        return ["code": code,
                "name": (cells.count > 1 ? cells[1] : "").trimmingCharacters(in: .whitespaces),
                "date": (cells.count > 2 ? cells[2] : "").trimmingCharacters(in: .whitespaces)]
    }
}

/// Appends new rows; returns how many were added, or throws if the file cannot be written.
func addToList(_ rows: [[String: Any]]) throws -> Int {
    var have = Set(readList().compactMap { $0["code"] })
    let stamp: String = { let f = DateFormatter(); f.dateFormat = "dd.MM.yyyy HH:mm"; return f.string(from: Date()) }()
    var out = ""
    if !FileManager.default.fileExists(atPath: listURL.path) || ((try? Data(contentsOf: listURL))?.isEmpty ?? true) {
        out += "\u{FEFF}Kennung;Name;Erzeugt am\r\n" // BOM, so Excel shows umlauts correctly
    }
    var added = 0
    for row in rows {
        let code = normalizeCode(row["code"] as? String ?? "")
        guard codeOK(code), !have.contains(code) else { continue }
        have.insert(code)
        var name = String((row["name"] as? String ?? "").trimmingCharacters(in: .whitespacesAndNewlines).prefix(300))
        if let first = name.first, "=+-@".contains(first) { name = "'" + name } // no formulas in Excel
        out += "\(code.prefix(4))-\(code.suffix(4));\(csvCell(name));\(stamp)\r\n"
        added += 1
    }
    guard let data = out.data(using: .utf8) else { return 0 }
    if FileManager.default.fileExists(atPath: listURL.path) {
        let handle = try FileHandle(forWritingTo: listURL)
        defer { try? handle.close() }
        try handle.seekToEnd()
        try handle.write(contentsOf: data)
    } else {
        try data.write(to: listURL)
    }
    return added
}
