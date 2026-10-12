@extends('layouts.app')
@section('title', 'Privacy policy')

@section('content')
<main class="container">
    <div class="card">
        <h1>Privacy policy</h1>
        <p class="muted">Last updated {{ now()->format('j F Y') }}</p>

        <p>{{ config('app.name') }} is the customer messaging tool used by {{ config('inbox.business_name') }} to answer messages sent to our Facebook Pages.</p>

        <h2>What we collect</h2>
        <p>When you send a message to one of our Facebook Pages, we receive and store your message, any attachments, your public profile name and profile picture, and, if you clicked one of our ads, which ad it was. We do not collect anything else from your Facebook account.</p>

        <h2>How we use it</h2>
        <p>We use this information only to reply to you and to handle your enquiries and orders. Some replies are written automatically by an AI service; your recent messages with us are sent to that service to generate the reply. We do not sell your information or use it for advertising.</p>

        <h2>How long we keep it</h2>
        <p>Conversations are kept for as long as needed to serve you, and you can ask us to delete them at any time.</p>

        <h2>Deleting your data</h2>
        <p>To have your messages and profile information deleted, send us a message on our Facebook Page saying "delete my data", or email <strong>{{ config('inbox.contact_email') }}</strong>. We will delete your data within 30 days and confirm when it is done.</p>

        <h2>Contact</h2>
        <p>{{ config('inbox.business_name') }} · {{ config('inbox.contact_email') }}</p>
    </div>
</main>
@endsection
