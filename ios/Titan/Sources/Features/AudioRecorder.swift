import AVFoundation
import Foundation

/// Records a short voice memo to an m4a (AAC) file for Whisper transcription. Handles the mic
/// permission prompt and the AVAudioSession lifecycle. Mirrors the web app's MediaRecorder →
/// `/coach/transcribe` flow: record → stop → upload the bytes → drop the text into the composer.
@MainActor
final class AudioRecorder: ObservableObject {
    @Published private(set) var isRecording = false
    @Published var denied = false                 // mic permission refused → prompt to enable in Settings

    private var recorder: AVAudioRecorder?
    private var url: URL?

    /// Begin recording. No-op (and sets `denied`) if the mic permission is refused.
    func start() async {
        guard !isRecording else { return }
        guard await ensurePermission() else { denied = true; return }
        let session = AVAudioSession.sharedInstance()
        do {
            try session.setCategory(.playAndRecord, mode: .default, options: [.duckOthers, .defaultToSpeaker])
            try session.setActive(true)
            let file = FileManager.default.temporaryDirectory
                .appendingPathComponent("titan-voice-\(UUID().uuidString).m4a")
            let settings: [String: Any] = [
                AVFormatIDKey: Int(kAudioFormatMPEG4AAC),
                AVSampleRateKey: 16_000,            // 16 kHz mono is plenty for speech + tiny uploads
                AVNumberOfChannelsKey: 1,
                AVEncoderAudioQualityKey: AVAudioQuality.medium.rawValue,
            ]
            let r = try AVAudioRecorder(url: file, settings: settings)
            r.record()
            recorder = r; url = file; isRecording = true
            Haptic.tap()
        } catch {
            cleanup()
        }
    }

    /// Stop and return the recorded bytes (nil if too short / nothing captured).
    func stop() -> Data? {
        guard let r = recorder else { return nil }
        r.stop()
        let captured = url.flatMap { try? Data(contentsOf: $0) }
        cleanup()
        guard let data = captured, data.count > 1_200 else { return nil }   // ignore accidental blips
        Haptic.soft()
        return data
    }

    /// Discard the in-progress recording.
    func cancel() {
        recorder?.stop()
        cleanup()
    }

    private func cleanup() {
        if let u = url { try? FileManager.default.removeItem(at: u) }
        recorder = nil; url = nil; isRecording = false
        try? AVAudioSession.sharedInstance().setActive(false, options: .notifyOthersOnDeactivation)
    }

    private func ensurePermission() async -> Bool {
        await withCheckedContinuation { cont in
            AVAudioApplication.requestRecordPermission { ok in cont.resume(returning: ok) }
        }
    }
}
