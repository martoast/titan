// swift-tools-version:5.9
import PackageDescription

// TitanCore — the platform-agnostic core of the Titan iOS app: the deterministic,
// fully-unit-testable pieces (HMAC signing, NUS frame decoding, windowing). Kept as a
// standalone SwiftPM package so it builds + tests with `swift test` on any Mac, with NO
// Xcode project, iPhone, or Apple Developer account required. The app target (CoreBluetooth,
// SwiftUI, APNs) is added later in Xcode and depends on this package.
let package = Package(
    name: "TitanCore",
    platforms: [.iOS(.v16), .macOS(.v13)],
    products: [
        .library(name: "TitanCore", targets: ["TitanCore"]),
    ],
    targets: [
        .target(name: "TitanCore"),
        .testTarget(name: "TitanCoreTests", dependencies: ["TitanCore"]),
    ]
)
