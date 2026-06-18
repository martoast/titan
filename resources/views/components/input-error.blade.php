@props(['messages'])

@if ($messages)
    <ul {{ $attributes->merge(['class' => 'auth-error']) }}>
        @foreach ((array) $messages as $message)
            <li>{{ $message }}</li>
        @endforeach
    </ul>
@endif
