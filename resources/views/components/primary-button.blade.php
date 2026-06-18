<button {{ $attributes->merge(['type' => 'submit', 'class' => 'auth-submit']) }}>
    {{ $slot }}
</button>
