import SwiftUI

/// A lightweight Markdown renderer for coach/chat text.
///
/// SwiftUI's `Text(LocalizedStringKey)` only interprets INLINE markdown (**bold**, *italic*, `code`,
/// [links]) — block constructs (headings, bullet/numbered lists, tables, blockquotes, code fences,
/// images) leak through as raw `#`, `-`, `|` characters. The coach's prompt tells it to use exactly
/// those, so this parses the common blocks and renders each natively, using `AttributedString` for the
/// inline styling within a block. Fully defensive: anything it can't parse degrades to plain text, and
/// it never throws.
struct MarkdownText: View {
    let markdown: String
    /// Secondary color for list bullets, numbers, table headers, quote text (adapts to the bubble).
    var secondary: Color = Theme.Palette.textDim
    /// Link / accent tint.
    var accent: Color = Theme.Palette.cyan

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            ForEach(Array(MarkdownBlock.parse(markdown).enumerated()), id: \.offset) { _, block in
                view(for: block)
            }
        }
        .tint(accent)
    }

    @ViewBuilder
    private func view(for block: MarkdownBlock) -> some View {
        switch block {
        case .heading(let level, let text):
            MarkdownBlock.inline(text)
                .font(level <= 1 ? Theme.Font.num(20) : (level == 2 ? Theme.Font.num(17) : Theme.Font.body.weight(.semibold)))
                .fixedSize(horizontal: false, vertical: true)

        case .paragraph(let text):
            MarkdownBlock.inline(text)
                .font(Theme.Font.body)
                .fixedSize(horizontal: false, vertical: true)

        case .bullets(let items):
            VStack(alignment: .leading, spacing: 4) {
                ForEach(Array(items.enumerated()), id: \.offset) { _, item in
                    HStack(alignment: .firstTextBaseline, spacing: 8) {
                        Text("•").font(Theme.Font.body).foregroundStyle(secondary)
                        MarkdownBlock.inline(item).font(Theme.Font.body).fixedSize(horizontal: false, vertical: true)
                    }
                }
            }

        case .ordered(let items):
            VStack(alignment: .leading, spacing: 4) {
                ForEach(Array(items.enumerated()), id: \.offset) { i, item in
                    HStack(alignment: .firstTextBaseline, spacing: 8) {
                        Text("\(i + 1).").font(Theme.Font.body.weight(.semibold)).foregroundStyle(secondary)
                            .frame(minWidth: 18, alignment: .trailing)
                        MarkdownBlock.inline(item).font(Theme.Font.body).fixedSize(horizontal: false, vertical: true)
                    }
                }
            }

        case .quote(let lines):
            HStack(alignment: .top, spacing: 8) {
                RoundedRectangle(cornerRadius: 1).fill(secondary.opacity(0.5)).frame(width: 3)
                MarkdownBlock.inline(lines.joined(separator: "\n"))
                    .font(Theme.Font.body).foregroundStyle(secondary).fixedSize(horizontal: false, vertical: true)
            }

        case .code(let code):
            ScrollView(.horizontal, showsIndicators: false) {
                Text(code).font(.system(.footnote, design: .monospaced))
                    .padding(10)
            }
            .background(Color.white.opacity(0.06), in: RoundedRectangle(cornerRadius: 10, style: .continuous))

        case .table(let headers, let rows):
            MarkdownTableView(headers: headers, rows: rows, secondary: secondary)

        case .rule:
            Divider().overlay(Theme.Palette.cardStroke)

        case .image(_, let url):
            if let u = URL(string: url) {
                AsyncImage(url: u) { img in
                    img.resizable().scaledToFit()
                } placeholder: {
                    RoundedRectangle(cornerRadius: 14).fill(Color.white.opacity(0.05)).frame(height: 160)
                }
                .frame(maxWidth: 240)
                .clipShape(RoundedRectangle(cornerRadius: 14, style: .continuous))
                .overlay(RoundedRectangle(cornerRadius: 14).strokeBorder(Theme.Palette.cardStroke))
            }
        }
    }
}

/// A markdown table rendered as an aligned, horizontally-scrollable grid.
private struct MarkdownTableView: View {
    let headers: [String]
    let rows: [[String]]
    let secondary: Color

    var body: some View {
        let cols = max(headers.count, rows.map(\.count).max() ?? 0)
        ScrollView(.horizontal, showsIndicators: false) {
            VStack(alignment: .leading, spacing: 0) {
                gridRow(headers, header: true, cols: cols)
                Divider().overlay(Theme.Palette.cardStroke)
                ForEach(Array(rows.enumerated()), id: \.offset) { _, row in
                    gridRow(row, header: false, cols: cols)
                }
            }
            .padding(10)
            .background(Color.white.opacity(0.04), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
            .overlay(RoundedRectangle(cornerRadius: 12).strokeBorder(Theme.Palette.cardStroke))
        }
    }

    private func gridRow(_ cells: [String], header: Bool, cols: Int) -> some View {
        HStack(spacing: 12) {
            ForEach(0..<cols, id: \.self) { c in
                MarkdownBlock.inline(c < cells.count ? cells[c] : "")
                    .font(header ? Theme.Font.micro : Theme.Font.body)
                    .foregroundStyle(header ? secondary : Theme.Palette.text)
                    .frame(minWidth: 60, alignment: .leading)
            }
        }
        .padding(.vertical, 5)
    }
}

/// One parsed markdown block. `parse` is line-based and forgiving — unknown lines become paragraphs.
enum MarkdownBlock {
    case heading(Int, String)
    case paragraph(String)
    case bullets([String])
    case ordered([String])
    case quote([String])
    case code(String)
    case table([String], [[String]])
    case rule
    case image(String, String)

    /// Inline markdown → a styled `Text`. Preserves whitespace and never throws (falls back to plain).
    static func inline(_ s: String) -> Text {
        if let attr = try? AttributedString(markdown: s, options: .init(
            allowsExtendedAttributes: true,
            interpretedSyntax: .inlineOnlyPreservingWhitespace,
            failurePolicy: .returnPartiallyParsedIfPossible)) {
            return Text(attr)
        }
        return Text(s)
    }

    static func parse(_ raw: String) -> [MarkdownBlock] {
        let lines = raw.replacingOccurrences(of: "\r\n", with: "\n").components(separatedBy: "\n")
        var blocks: [MarkdownBlock] = []
        var para: [String] = []
        var i = 0

        func flushPara() {
            let text = para.joined(separator: "\n").trimmingCharacters(in: .whitespacesAndNewlines)
            if !text.isEmpty { blocks.append(.paragraph(text)) }
            para = []
        }

        while i < lines.count {
            let line = lines[i]
            let t = line.trimmingCharacters(in: .whitespaces)

            // Fenced code block.
            if t.hasPrefix("```") {
                flushPara()
                var code: [String] = []
                i += 1
                while i < lines.count, !lines[i].trimmingCharacters(in: .whitespaces).hasPrefix("```") {
                    code.append(lines[i]); i += 1
                }
                i += 1   // skip the closing fence
                blocks.append(.code(code.joined(separator: "\n")))
                continue
            }

            // Horizontal rule.
            if t == "---" || t == "***" || t == "___" {
                flushPara(); blocks.append(.rule); i += 1; continue
            }

            // ATX heading.
            if let level = headingLevel(t) {
                flushPara()
                let text = String(t.drop { $0 == "#" }).trimmingCharacters(in: .whitespaces)
                blocks.append(.heading(level, text)); i += 1; continue
            }

            // Standalone image: ![alt](url)
            if let (alt, url) = standaloneImage(t) {
                flushPara(); blocks.append(.image(alt, url)); i += 1; continue
            }

            // Table: a header row followed by a |---|---| separator.
            if t.contains("|"), i + 1 < lines.count, isTableSeparator(lines[i + 1]) {
                flushPara()
                let headers = splitRow(t)
                i += 2
                var rows: [[String]] = []
                while i < lines.count {
                    let r = lines[i].trimmingCharacters(in: .whitespaces)
                    guard r.contains("|"), !r.isEmpty else { break }
                    rows.append(splitRow(r)); i += 1
                }
                blocks.append(.table(headers, rows)); continue
            }

            // Bullet list.
            if isBullet(t) {
                flushPara()
                var items: [String] = []
                while i < lines.count, isBullet(lines[i].trimmingCharacters(in: .whitespaces)) {
                    items.append(bulletContent(lines[i].trimmingCharacters(in: .whitespaces))); i += 1
                }
                blocks.append(.bullets(items)); continue
            }

            // Ordered list.
            if isOrdered(t) {
                flushPara()
                var items: [String] = []
                while i < lines.count, isOrdered(lines[i].trimmingCharacters(in: .whitespaces)) {
                    items.append(orderedContent(lines[i].trimmingCharacters(in: .whitespaces))); i += 1
                }
                blocks.append(.ordered(items)); continue
            }

            // Blockquote.
            if t.hasPrefix(">") {
                flushPara()
                var quote: [String] = []
                while i < lines.count, lines[i].trimmingCharacters(in: .whitespaces).hasPrefix(">") {
                    let q = lines[i].trimmingCharacters(in: .whitespaces)
                    quote.append(String(q.dropFirst()).trimmingCharacters(in: .whitespaces)); i += 1
                }
                blocks.append(.quote(quote)); continue
            }

            // Blank line ends a paragraph; otherwise accumulate.
            if t.isEmpty { flushPara() } else { para.append(line) }
            i += 1
        }
        flushPara()
        return blocks.isEmpty ? [.paragraph(raw)] : blocks
    }

    // MARK: line classifiers

    private static func headingLevel(_ t: String) -> Int? {
        guard t.hasPrefix("#") else { return nil }
        let hashes = t.prefix { $0 == "#" }.count
        guard hashes <= 6, t.dropFirst(hashes).first == " " else { return nil }
        return hashes
    }

    private static func isBullet(_ t: String) -> Bool {
        (t.hasPrefix("- ") || t.hasPrefix("* ") || t.hasPrefix("+ ")) && t.count > 2
    }

    private static func bulletContent(_ t: String) -> String { String(t.dropFirst(2)).trimmingCharacters(in: .whitespaces) }

    private static func isOrdered(_ t: String) -> Bool {
        guard let dot = t.firstIndex(of: ".") else { return false }
        let num = t[t.startIndex..<dot]
        return !num.isEmpty && num.allSatisfy(\.isNumber) && t.index(after: dot) < t.endIndex && t[t.index(after: dot)] == " "
    }

    private static func orderedContent(_ t: String) -> String {
        guard let dot = t.firstIndex(of: ".") else { return t }
        return String(t[t.index(after: dot)...]).trimmingCharacters(in: .whitespaces)
    }

    private static func isTableSeparator(_ line: String) -> Bool {
        let t = line.trimmingCharacters(in: .whitespaces)
        guard t.contains("-"), t.contains("|") else { return false }
        return t.allSatisfy { $0 == "|" || $0 == "-" || $0 == ":" || $0 == " " }
    }

    private static func splitRow(_ t: String) -> [String] {
        var s = Substring(t)
        if s.hasPrefix("|") { s = s.dropFirst() }
        if s.hasSuffix("|") { s = s.dropLast() }
        return s.components(separatedBy: "|").map { $0.trimmingCharacters(in: .whitespaces) }
    }

    /// Parse a line that is EXACTLY a single image `![alt](url)`.
    private static func standaloneImage(_ t: String) -> (String, String)? {
        guard t.hasPrefix("!["), t.hasSuffix(")"),
              let altClose = t.range(of: "]("), let _ = t.range(of: ")", options: .backwards) else { return nil }
        let alt = String(t[t.index(t.startIndex, offsetBy: 2)..<altClose.lowerBound])
        let url = String(t[altClose.upperBound..<t.index(before: t.endIndex)])
        guard url.hasPrefix("http") else { return nil }
        return (alt, url)
    }
}
