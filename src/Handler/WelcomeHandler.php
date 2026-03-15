<?php

// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at https://mozilla.org/MPL/2.0/.
//
// Copyright (c) 2024-2026 TripleACS Pty Ltd t/a 2pi Software

declare(strict_types=1);

namespace Relay\Handler;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class WelcomeHandler
{
    public function __invoke(Request $request, Response $response): Response
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KrillNotes Relay</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        html { scroll-behavior: smooth; }

        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #fafaf8;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            color: #33312c;
            -webkit-font-smoothing: antialiased;
            padding: 2rem;
            position: relative;
            overflow: hidden;
        }

        /* Decorative gradient overlay like the website hero */
        body::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background:
                radial-gradient(ellipse 600px 400px at 30% 20%, #e6fcf8 0%, transparent 70%),
                radial-gradient(ellipse 500px 350px at 70% 80%, #fff0ec 0%, transparent 70%);
            pointer-events: none;
            z-index: 0;
        }

        .card {
            position: relative;
            z-index: 1;
            background: #ffffff;
            border: 1px solid #e8e6e0;
            border-radius: 16px;
            padding: 3rem 2.5rem;
            max-width: 480px;
            width: 100%;
            text-align: center;
            box-shadow: 0 8px 30px rgba(10, 22, 40, 0.12);
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .logo {
            display: block;
            margin-left: auto;
            margin-right: auto;
            width: 120px;
            height: 120px;
            border-radius: 10px;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 12px rgba(10, 22, 40, 0.08);
            animation: gentle-bob 3s ease-in-out infinite;
        }

        @keyframes gentle-bob {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-3px); }
        }

        .label {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.78rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: #ff6b4a;
            margin-bottom: 0.75rem;
        }

        .label-dot {
            width: 6px;
            height: 6px;
            background: #4de8d0;
            border-radius: 50%;
            animation: pulse 2s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.6; transform: scale(1.5); }
        }

        h1 {
            font-size: clamp(1.6rem, 3vw, 2rem);
            font-weight: 700;
            color: #0a1628;
            letter-spacing: -0.02em;
            line-height: 1.2;
            margin-bottom: 1rem;
        }

        p {
            font-size: 1rem;
            line-height: 1.65;
            margin-bottom: 1.75rem;
            color: #6b675d;
        }

        p strong {
            color: #0a1628;
            font-weight: 600;
        }

        .cta {
            display: inline-block;
            background: #ff6b4a;
            color: #fff;
            text-decoration: none;
            font-weight: 600;
            font-size: 1rem;
            padding: 0.75rem 1.5rem;
            border-radius: 10px;
            transition: all 0.2s;
            box-shadow: 0 4px 14px rgba(255, 107, 74, 0.2);
        }

        .cta:hover {
            background: #ff8a6f;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(255, 107, 74, 0.2);
        }

        .footer {
            margin-top: 2rem;
            font-size: 0.8rem;
            color: #9e9a8f;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.4rem;
        }

        .footer svg {
            flex-shrink: 0;
        }

        @media (max-width: 640px) {
            body { padding: 1rem; }
            .card { padding: 2rem 1.5rem; }
        }
    </style>
</head>
<body>
    <div class="card">
        <img src="/logo.png" alt="KrillNotes" class="logo">
        <div class="label"><span class="label-dot"></span> Sync &amp; Relay Service</div>
        <h1>KrillNotes Relay</h1>
        <p>
            This server is a secure store-and-forward relay for
            <strong>KrillNotes Swarms</strong>. It syncs end-to-end encrypted
            data between your devices &mdash; and peers. It is not meant to be
            accessed directly from a browser.
        </p>
        <a href="https://krillnotes.com" class="cta">Get KrillNotes</a>
        <div class="footer">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#4de8d0" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            End-to-end encrypted. The relay cannot read your notes.
        </div>
    </div>
</body>
</html>
HTML;

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
