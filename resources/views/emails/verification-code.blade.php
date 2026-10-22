<p>Hello,</p>

<p>
    {{ $purpose === 'EMAIL_VERIFICATION' ? 'Use this code to verify your email address:' : 'Use this code to reset your password:' }}
</p>

<p style="font-size: 24px; font-weight: bold; letter-spacing: 4px;">{{ $code }}</p>

<p>This code expires in 10 minutes and can only be used once. Do not share it with anyone.</p>
