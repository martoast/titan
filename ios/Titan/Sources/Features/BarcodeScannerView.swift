import SwiftUI
#if canImport(VisionKit)
import VisionKit
#endif

/// A live barcode scanner (VisionKit's on-device `DataScannerViewController`) — point at a product's
/// barcode and it reads the GTIN/EAN/UPC, all on-device. The code goes to the server, which maps it to
/// the product's macros via Open Food Facts. Requires a recent iPhone (A12+, iOS 16+); callers gate on
/// `isAvailable` and fall back to the photo scan otherwise.
struct BarcodeScannerView: View {
    let onCode: (String) -> Void
    @Environment(\.dismiss) private var dismiss

    /// Can this device do live barcode scanning at all? (false on the simulator + older phones.)
    static var isAvailable: Bool {
        #if canImport(VisionKit)
        if #available(iOS 16.0, *) { return DataScannerViewController.isSupported && DataScannerViewController.isAvailable }
        #endif
        return false
    }

    var body: some View {
        ZStack(alignment: .top) {
            Color.black.ignoresSafeArea()
            #if canImport(VisionKit)
            if #available(iOS 16.0, *), Self.isAvailable {
                Scanner(onCode: { code in onCode(code); dismiss() })
                    .ignoresSafeArea()
            } else {
                unavailable
            }
            #else
            unavailable
            #endif

            HStack {
                Button { dismiss() } label: {
                    Image(systemName: "xmark").font(.system(size: 15, weight: .bold)).foregroundStyle(.white)
                        .frame(width: 38, height: 38).background(.ultraThinMaterial, in: Circle())
                }
                Spacer()
                Text("Point at a barcode").font(Theme.Font.body.weight(.semibold)).foregroundStyle(.white)
                    .padding(.horizontal, 14).padding(.vertical, 8).background(.ultraThinMaterial, in: Capsule())
                Spacer()
                Color.clear.frame(width: 38, height: 38)
            }
            .padding(Theme.Space.m)
        }
    }

    private var unavailable: some View {
        VStack(spacing: Theme.Space.s) {
            Image(systemName: "barcode.viewfinder").font(.system(size: 40)).foregroundStyle(.white.opacity(0.7))
            Text("Barcode scanning needs a recent iPhone.").font(Theme.Font.body).foregroundStyle(.white)
                .multilineTextAlignment(.center)
            Text("Snap the nutrition label instead.").font(Theme.Font.micro).foregroundStyle(.white.opacity(0.6))
        }.padding(Theme.Space.xl).frame(maxWidth: .infinity, maxHeight: .infinity)
    }
}

#if canImport(VisionKit)
@available(iOS 16.0, *)
private struct Scanner: UIViewControllerRepresentable {
    let onCode: (String) -> Void

    func makeUIViewController(context: Context) -> DataScannerViewController {
        let scanner = DataScannerViewController(
            // UPC-A is carried as EAN-13 (leading 0), so .ean13 covers it too.
            recognizedDataTypes: [.barcode(symbologies: [.ean13, .ean8, .upce, .code128])],
            qualityLevel: .balanced,
            recognizesMultipleItems: false,
            isHighFrameRateTrackingEnabled: false,
            isHighlightingEnabled: true)
        scanner.delegate = context.coordinator
        return scanner
    }

    func updateUIViewController(_ vc: DataScannerViewController, context: Context) {
        try? vc.startScanning()
    }

    func makeCoordinator() -> Coordinator { Coordinator(onCode: onCode) }

    final class Coordinator: NSObject, DataScannerViewControllerDelegate {
        private let onCode: (String) -> Void
        private var fired = false
        init(onCode: @escaping (String) -> Void) { self.onCode = onCode }

        func dataScanner(_ scanner: DataScannerViewController, didAdd added: [RecognizedItem], allItems: [RecognizedItem]) { handle(added) }
        func dataScanner(_ scanner: DataScannerViewController, didTapOn item: RecognizedItem) { handle([item]) }

        private func handle(_ items: [RecognizedItem]) {
            guard !fired else { return }
            for case let .barcode(barcode) in items {
                if let code = barcode.payloadStringValue, code.trimmingCharacters(in: .whitespaces).count >= 8 {
                    fired = true
                    UINotificationFeedbackGenerator().notificationOccurred(.success)
                    onCode(code.trimmingCharacters(in: .whitespaces))
                    return
                }
            }
        }
    }
}
#endif
