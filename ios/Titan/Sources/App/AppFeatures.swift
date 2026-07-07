import Foundation

/// Build-level feature gates.
///
/// `community` (the social feed + profiles = user-generated content) is turned OFF for the public
/// App Store build until it ships report + block moderation. With it off, the app has no UGC surface,
/// so we can honestly answer "No" to User-Generated Content in the App Store age-rating questionnaire
/// (App Review Guideline 1.2). Flip it back to `true` once moderation lands to restore the tab.
enum AppFeatures {
    static let community = false
}
