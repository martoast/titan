import XCTest
@testable import TitanCore

/// Golden vectors generated from the REAL backend recipe:
///   key = hash('sha256', secret)         // PHP: hash('sha256', $secret)
///   v1  = hash_hmac('sha256', "$t.$body", key)
/// (matches App\Services\Wearables\TerraClient::verifyDeviceSignature and the web bridge
/// `_sign()`). If the iOS Signer reproduces these v1 hashes, the ingest auth port is correct.
final class SignerTests: XCTestCase {

    struct Vector { let secret: String; let t: Int; let body: String; let v1: String }

    let vectors: [Vector] = [
        Vector(secret: "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef",
               t: 1750000000,
               body: #"{"batch_uid":"01J0TEST","windows":[]}"#,
               v1: "7e8619ff3cee01b469c8a2cd2bfb80d12246aa12c26b6283ea36c48b3d8a4ca0"),
        Vector(secret: "feedface00000000000000000000000000000000000000000000000000000000",
               t: 1750700000,
               body: #"{"ping":true}"#,
               v1: "a218a31735d52d1fe26752e44a7f48f506c8ef2582fcd1f30d269de15c6996b1"),
        Vector(secret: "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0a1b2",
               t: 1750800123,
               body: "hello",
               v1: "df4dfcd978a988558d72f71f6076f4a0aac171bc66608b05663c04652b25e5f9"),
    ]

    func testSignerMatchesBackendGoldenVectors() {
        for v in vectors {
            let sig = Signer.sign(bodyString: v.body, deviceId: "titan_band_test", secret: v.secret, t: v.t)
            XCTAssertEqual(sig.v1, v.v1, "HMAC mismatch for body \(v.body)")
            XCTAssertEqual(sig.titanSignature, "t=\(v.t),v1=\(v.v1)")
            XCTAssertEqual(sig.deviceId, "titan_band_test")
        }
    }

    /// Signing raw bytes (e.g. a gzipped-then-decoded body) must equal signing the string form.
    func testDataAndStringBodiesAgree() {
        let body = #"{"batch_uid":"01J0TEST","windows":[]}"#
        let a = Signer.sign(bodyString: body, deviceId: "d", secret: vectors[0].secret, t: 1750000000)
        let b = Signer.sign(body: Data(body.utf8), deviceId: "d", secret: vectors[0].secret, t: 1750000000)
        XCTAssertEqual(a.v1, b.v1)
    }
}
