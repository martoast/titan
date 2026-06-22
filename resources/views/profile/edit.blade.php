<x-titan-layout title="Profile" subtitle="Your account & security">
    <div class="mx-auto max-w-xl space-y-4">
        <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4 md:p-5">
            @include('profile.partials.update-profile-information-form')
        </div>

        <div class="rounded-2xl border border-white/5 bg-white/[0.03] p-4 md:p-5">
            @include('profile.partials.update-password-form')
        </div>

        <div class="rounded-2xl border border-rose-500/15 bg-rose-500/[0.04] p-4 md:p-5">
            @include('profile.partials.delete-user-form')
        </div>
    </div>
</x-titan-layout>
