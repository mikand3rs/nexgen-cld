<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| NexGen CLD — Front Controller
|--------------------------------------------------------------------------
|
| Routes:
|   GET  /        Chat interface
|   POST /chat    AI chat endpoint
|   GET  /health  PostgreSQL health check
|
*/

$path = parse_url(
    $_SERVER['REQUEST_URI'] ?? '/',
    PHP_URL_PATH
);

if (!is_string($path)) {
    $path = '/';
}

/*
|--------------------------------------------------------------------------
| AI Chat Endpoint
|--------------------------------------------------------------------------
*/

if ($path === '/chat') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        header('Allow: POST');
        http_response_code(405);

        echo json_encode([
            'error' => 'Method not allowed.',
        ]);

        exit;
    }

    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput ?: '', true);

    if (!is_array($input)) {
        http_response_code(400);

        echo json_encode([
            'error' => 'Invalid JSON request.',
        ]);

        exit;
    }

    $message = trim(
        is_string($input['message'] ?? null)
            ? $input['message']
            : ''
    );

    if ($message === '') {
        http_response_code(400);

        echo json_encode([
            'error' => 'Message is required.',
        ]);

        exit;
    }

    // Prevent excessively large individual chat messages.
    if (strlen($message) > 12000) {
        http_response_code(413);

        echo json_encode([
            'error' => 'Message is too long.',
        ]);

        exit;
    }

    // Connector is outside the public web root.
    require_once __DIR__ . '/../ai-connect.php';

    try {
        $reply = ai_chat(
            $message,
            'You are a helpful assistant for NexGen CLD. '
            . 'Answer clearly and concisely. '
            . 'Provide practical next steps when appropriate.'
        );

        echo json_encode(
            ['reply' => $reply],
            JSON_UNESCAPED_UNICODE
            | JSON_INVALID_UTF8_SUBSTITUTE
        );

    } catch (Throwable $e) {
        error_log(
            'NexGen CLD AI chat error: ' . $e->getMessage()
        );

        http_response_code(502);

        echo json_encode([
            'error' => 'The AI is unavailable right now.',
        ]);
    }

    exit;
}

/*
|--------------------------------------------------------------------------
| Database Health Endpoint
|--------------------------------------------------------------------------
*/

if ($path === '/health') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    try {
        $dbHost = getenv('DB_HOST') ?: 'postgres';
        $dbPort = getenv('DB_PORT') ?: '5432';
        $dbName = getenv('DB_NAME') ?: 'nexgen';
        $dbUser = getenv('DB_USER') ?: 'nexgen';
        $dbPassword = getenv('DB_PASSWORD') ?: '';

        $pdo = new PDO(
            sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                $dbHost,
                $dbPort,
                $dbName
            ),
            $dbUser,
            $dbPassword,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]
        );

        $pdo->query('SELECT 1');

        echo json_encode([
            'status' => 'ok',
            'application' => 'NexGen CLD',
            'database' => 'connected',
        ]);

    } catch (Throwable $e) {
        error_log(
            'NexGen CLD database health check failed: '
            . $e->getMessage()
        );

        http_response_code(503);

        echo json_encode([
            'status' => 'error',
            'application' => 'NexGen CLD',
            'database' => 'unavailable',
        ]);
    }

    exit;
}

/*
|--------------------------------------------------------------------------
| Route Validation
|--------------------------------------------------------------------------
*/

if ($path !== '/') {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(404);

    echo json_encode([
        'error' => 'Not found',
    ]);

    exit;
}

?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <meta
        name="description"
        content="NexGen CLD business assistant."
    >

    <title>NexGen CLD Chat</title>

    <style>
        :root {
            color-scheme: light;
            font-family: Georgia, serif;
            color: #17211b;
            background: #e7eee8;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;

            background: radial-gradient(
                circle at top left,
                #f8fbf6,
                #dce8df 55%,
                #c3d6c8
            );
        }

        main {
            width: min(760px, 100%);
            background: #fbfcf8;
            border: 1px solid #b9cbbd;
            box-shadow: 0 20px 60px #37533d26;
        }

        header {
            padding: 28px 30px 20px;
            border-bottom: 1px solid #d5e0d7;
        }

        h1 {
            margin: 0;
            font-size: clamp(2rem, 6vw, 3.5rem);
            font-weight: 400;
            letter-spacing: -0.03em;
        }

        header p {
            margin: 8px 0 0;
            color: #607066;
            font: 0.95rem/1.5 system-ui, sans-serif;
        }

        #messages {
            min-height: 360px;
            max-height: 55vh;
            overflow-y: auto;
            padding: 24px 30px;

            display: grid;
            align-content: start;
            gap: 14px;
        }

        .message {
            max-width: 82%;
            padding: 13px 16px;
            border-radius: 2px;

            font: 1rem/1.55 system-ui, sans-serif;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }

        .user {
            justify-self: end;
            color: #fff;
            background: #286044;
        }

        .assistant {
            justify-self: start;
            background: #e8f0e9;
        }

        .error {
            color: #8b2424;
            background: #f8e8e8;
        }

        form {
            display: flex;
            gap: 10px;
            padding: 18px 30px 28px;
            border-top: 1px solid #d5e0d7;
        }

        textarea {
            flex: 1;
            min-width: 0;
            min-height: 52px;
            resize: vertical;

            border: 1px solid #aebfb2;
            padding: 13px;

            font: 1rem system-ui, sans-serif;
            background: #fff;
        }

        textarea:focus,
        button:focus-visible {
            outline: 2px solid #286044;
            outline-offset: 2px;
        }

        button {
            border: 0;
            padding: 0 22px;

            color: #fff;
            background: #286044;

            font: 600 0.95rem system-ui, sans-serif;
            cursor: pointer;
        }

        button:disabled {
            opacity: 0.55;
            cursor: wait;
        }

        @media (max-width: 520px) {
            body {
                padding: 0;
            }

            main {
                min-height: 100vh;
            }

            header,
            #messages,
            form {
                padding-left: 18px;
                padding-right: 18px;
            }

            form {
                align-items: stretch;
            }

            button {
                padding: 0 16px;
            }
        }
    </style>
</head>

<body>

<main>
    <header>
        <h1>NexGen CLD</h1>

        <p>
            A focused workspace for clear answers and practical next steps.
        </p>
    </header>

    <section
        id="messages"
        aria-live="polite"
        aria-label="Conversation"
    >
        <div class="message assistant">
            Hello. What can I help you work through?
        </div>
    </section>

    <form id="chat-form">
        <textarea
            id="message"
            placeholder="Write a message..."
            aria-label="Message"
            maxlength="12000"
            required
        ></textarea>

        <button type="submit">Send</button>
    </form>
</main>

<script>
    const form = document.querySelector('#chat-form');
    const input = document.querySelector('#message');
    const messages = document.querySelector('#messages');
    const button = form.querySelector('button');

    function addMessage(text, role) {
        const element = document.createElement('div');

        element.className = `message ${role}`;

        // Treat all user and AI content as plain text.
        element.textContent = text;

        messages.append(element);
        messages.scrollTop = messages.scrollHeight;
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        const message = input.value.trim();

        if (!message) {
            return;
        }

        addMessage(message, 'user');

        input.value = '';
        input.disabled = true;
        button.disabled = true;
        button.textContent = 'Thinking...';

        try {
            const response = await fetch('/chat', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    message: message
                })
            });

            const data = await response.json();

            if (!response.ok) {
                addMessage(
                    data.error || 'The request failed.',
                    'assistant error'
                );
            } else {
                addMessage(
                    data.reply || 'The AI returned an empty response.',
                    'assistant'
                );
            }

        } catch (error) {
            addMessage(
                'Unable to reach the chat service. Please try again.',
                'assistant error'
            );

        } finally {
            input.disabled = false;
            button.disabled = false;
            button.textContent = 'Send';
            input.focus();
        }
    });
</script>

</body>
</html>