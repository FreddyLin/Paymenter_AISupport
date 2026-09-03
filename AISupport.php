<?php

namespace Paymenter\Extensions\Others\AISupport;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Extension;
use App\Events\TicketMessage\Created;
use App\Models\TicketMessage;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

#[ExtensionMeta(
    name: 'AI Support',
    description: 'Automatically responds to support tickets using AI (OpenAI, Claude, Gemini, Mistral, and more).',
    version: '1.1.0',
    author: 'Buster4126',
)]
class AISupport extends Extension
{
    private const ESCALATE_MARKER = '[[ESCALATE]]';
    private const MAX_HISTORY_MESSAGES = 10;

    public function getConfig($values = []): array
    {
        $departments = (array) config('settings.ticket_departments', []);

        return [
            // ── Provider ──────────────────────────────────────────────────
            [
                'name'        => 'ai_provider',
                'label'       => 'AI Provider',
                'type'        => 'select',
                'description' => 'Which AI service should handle the ticket replies.',
                'required'    => true,
                'options'     => [
                    ['label' => 'OpenAI (ChatGPT)',           'value' => 'openai'],
                    ['label' => 'Anthropic (Claude)',          'value' => 'anthropic'],
                    ['label' => 'Google (Gemini)',             'value' => 'gemini'],
                    ['label' => 'Mistral AI',                  'value' => 'mistral'],
                    ['label' => 'Custom (OpenAI-compatible)',  'value' => 'custom'],
                ],
            ],
            [
                'name'        => 'api_key',
                'label'       => 'API Key',
                'type'        => 'password',
                'description' => 'Your API key for the selected provider. Stored encrypted.',
                'required'    => true,
                'encrypted'   => true,
            ],
            [
                'name'        => 'api_model',
                'label'       => 'Model',
                'type'        => 'text',
                'description' => 'Model name to use, e.g. gpt-4o · claude-sonnet-4-6 · gemini-2.5-flash · mistral-large-latest',
                'required'    => true,
            ],
            [
                'name'        => 'api_base_url',
                'label'       => 'Custom API Base URL',
                'type'        => 'text',
                'description' => 'Only required when provider is "Custom". Must be an OpenAI-compatible endpoint, e.g. https://api.opencode.ai/v1',
                'required'    => false,
            ],

            // ── Bot identity ──────────────────────────────────────────────
            [
                'name'        => 'bot_user_id',
                'label'       => 'Bot User ID',
                'type'        => 'number',
                'description' => 'The Paymenter user ID the AI will reply as. Create a dedicated staff user (e.g. "AI Support Bot") and paste its numeric ID here. The extension will never reply to messages from this user, preventing reply loops.',
                'required'    => true,
            ],

            // ── Prompts ───────────────────────────────────────────────────
            [
                'name'        => 'system_prompt',
                'label'       => 'System Prompt',
                'type'        => 'textarea',
                'description' => 'Base instructions for the AI — who it is, the company name, tone, and behaviour. The escalation instruction is appended automatically.',
                'required'    => true,
                'default'     => "You are a friendly and professional support agent for [Your Company Name]. You help customers with questions about their hosting services, accounts, and products.\n\nGuidelines:\n- Always greet the customer politely and address their question directly\n- Keep responses concise and easy to understand — avoid unnecessary technical jargon\n- If a customer reports a technical issue, ask for relevant details (error messages, domain, service ID) if not already provided\n- Do not make promises about refunds, custom deals, or uptime guarantees unless stated in the hosting information\n- Do not share pricing or plan details unless they are listed in the hosting information below\n- If you are unsure about something, escalate instead of guessing",
            ],
            [
                'name'        => 'hosting_info',
                'label'       => 'Hosting / Company Information',
                'type'        => 'textarea',
                'description' => 'Server specs, offered plans, policies, FAQs, or any other context the AI should know when answering tickets. Leave empty if not needed.',
                'required'    => false,
            ],

            // ── Department filter ─────────────────────────────────────────
            [
                'name'          => 'allowed_departments',
                'label'         => 'Allowed Departments',
                'type'          => 'select',
                'multiple'      => true,
                'database_type' => 'array',
                'description'   => 'The AI will only respond to tickets in these departments. Leave empty to allow all departments. Departments are configured in Settings → Tickets.',
                'required'      => false,
                'options'       => array_values($departments),
            ],

            // ── Escalation ────────────────────────────────────────────────
            [
                'name'        => 'discord_webhook',
                'label'       => 'Discord Webhook URL',
                'type'        => 'text',
                'description' => 'Optional. When the AI cannot answer a ticket you will receive a Discord notification with the ticket details so a human agent can follow up.',
                'required'    => false,
            ],
        ];
    }

    public function installed(): void
    {
        $extension = \App\Models\Extension::where('extension', 'AISupport')->first();
        if (!$extension) {
            return;
        }

        $defaults = [
            'system_prompt' => "You are a friendly and professional support agent for [Your Company Name]. You help customers with questions about their hosting services, accounts, and products.\n\nGuidelines:\n- Always greet the customer politely and address their question directly\n- Keep responses concise and easy to understand — avoid unnecessary technical jargon\n- If a customer reports a technical issue, ask for relevant details (error messages, domain, service ID) if not already provided\n- Do not make promises about refunds, custom deals, or uptime guarantees unless stated in the hosting information\n- Do not share pricing or plan details unless they are listed in the hosting information below\n- If you are unsure about something, escalate instead of guessing",
        ];

        foreach ($defaults as $key => $value) {
            $extension->settings()->firstOrCreate(
                ['key' => $key],
                ['type' => 'string', 'value' => $value, 'encrypted' => false]
            );
        }
    }

    public function boot(): void
    {
        Event::listen(Created::class, function (Created $event): void {
            try {
                $this->handleTicketMessage($event->ticketMessage);
            } catch (\Throwable $e) {
                Log::error('AISupport: Unhandled exception', [
                    'message'    => $e->getMessage(),
                    'ticket_id'  => $event->ticketMessage->ticket_id ?? null,
                ]);
            }
        });
    }

    // ── Core handler ─────────────────────────────────────────────────────────

    private function handleTicketMessage(TicketMessage $message): void
    {
        $botUserId = (int) $this->config('bot_user_id');

        // Never reply to our own messages (prevents infinite loops)
        if ($message->user_id === $botUserId) {
            return;
        }

        $ticket = $message->ticket;

        // Only reply to messages from the ticket owner (customer).
        // If staff or another agent replied, stay silent.
        if ($message->user_id !== $ticket->user_id) {
            return;
        }

        // If a human staff member has already replied to this ticket,
        // the AI hands off completely and stays silent from that point on.
        $humanHasReplied = $ticket->messages()
            ->where('user_id', '!=', $ticket->user_id)
            ->where('user_id', '!=', $botUserId)
            ->exists();

        if ($humanHasReplied) {
            return;
        }

        // Department filter
        $allowedDepartments = $this->config('allowed_departments') ?? [];
        if (!empty($allowedDepartments) && !in_array($ticket->department, $allowedDepartments, true)) {
            return;
        }

        $messages = $this->buildMessages($ticket, $botUserId);
        $response = $this->callAI($messages);

        // AI returned nothing (API error) or explicitly escalated
        if ($response === null || str_contains($response, self::ESCALATE_MARKER)) {
            $this->sendDiscordNotification($ticket, $message);
            return;
        }

        TicketMessage::create([
            'ticket_id' => $ticket->id,
            'user_id'   => $botUserId,
            'message'   => trim($response),
        ]);
    }

    // ── Message builder ───────────────────────────────────────────────────────

    private function buildMessages(\App\Models\Ticket $ticket, int $botUserId): array
    {
        $systemPrompt = trim($this->config('system_prompt') ?? '');
        $hostingInfo  = trim($this->config('hosting_info') ?? '');

        if ($hostingInfo !== '') {
            $systemPrompt .= "\n\n## Company / Hosting Information\n" . $hostingInfo;
        }

        $systemPrompt .= "\n\n## Escalation Rule\n"
            . "If you are unable to answer the customer's question, respond with exactly: "
            . self::ESCALATE_MARKER
            . "\nDo not include that marker in any other context.";

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];

        // Inject ticket subject as context
        $messages[] = [
            'role'    => 'user',
            'content' => "[Support ticket subject: {$ticket->subject}]",
        ];
        $messages[] = [
            'role'    => 'assistant',
            'content' => "Understood. I will assist with this ticket.",
        ];

        // Last N messages so we don't exceed token limits on long threads
        $history = $ticket->messages()
            ->orderBy('created_at', 'asc')
            ->take(self::MAX_HISTORY_MESSAGES)
            ->get();

        $customerId = $ticket->user_id;

        foreach ($history as $msg) {
            // Customer messages → user, everyone else (bot + human staff) → assistant
            $role       = ($msg->user_id === $customerId) ? 'user' : 'assistant';
            $messages[] = ['role' => $role, 'content' => $msg->message];
        }

        return $messages;
    }

    // ── AI dispatch ───────────────────────────────────────────────────────────

    private function callAI(array $messages): ?string
    {
        return match ($this->config('ai_provider')) {
            'anthropic' => $this->callAnthropic($messages),
            'gemini'    => $this->callGemini($messages),
            default     => $this->callOpenAICompatible($messages),
        };
    }

    private function callOpenAICompatible(array $messages): ?string
    {
        $baseUrl = match ($this->config('ai_provider')) {
            'openai'  => 'https://api.openai.com/v1',
            'mistral' => 'https://api.mistral.ai/v1',
            'custom'  => rtrim($this->config('api_base_url') ?? '', '/'),
            default   => 'https://api.openai.com/v1',
        };

        if (empty($baseUrl)) {
            Log::error('AISupport: Custom provider selected but no base URL configured.');
            return null;
        }

        try {
            $response = Http::withToken($this->config('api_key'))
                ->timeout(45)
                ->post("{$baseUrl}/chat/completions", [
                    'model'      => $this->config('api_model'),
                    'messages'   => $messages,
                    'max_tokens' => 1024,
                ]);

            if (!$response->successful()) {
                Log::error('AISupport: OpenAI-compatible API error', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return null;
            }

            return $response->json('choices.0.message.content');
        } catch (\Throwable $e) {
            Log::error('AISupport: OpenAI-compatible request failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function callAnthropic(array $messages): ?string
    {
        // Anthropic uses a separate system field, not a message role
        $systemPrompt      = '';
        $anthropicMessages = [];

        foreach ($messages as $msg) {
            if ($msg['role'] === 'system') {
                $systemPrompt = $msg['content'];
            } else {
                $anthropicMessages[] = $msg;
            }
        }

        try {
            $response = Http::withHeaders([
                'x-api-key'         => $this->config('api_key'),
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ])
                ->timeout(45)
                ->post('https://api.anthropic.com/v1/messages', [
                    'model'      => $this->config('api_model'),
                    'max_tokens' => 1024,
                    'system'     => $systemPrompt,
                    'messages'   => $anthropicMessages,
                ]);

            if (!$response->successful()) {
                Log::error('AISupport: Anthropic API error', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return null;
            }

            return $response->json('content.0.text');
        } catch (\Throwable $e) {
            Log::error('AISupport: Anthropic request failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function callGemini(array $messages): ?string
    {
        // Gemini uses a separate systemInstruction field and "model" instead of "assistant"
        $systemPrompt   = '';
        $geminiContents = [];

        foreach ($messages as $msg) {
            if ($msg['role'] === 'system') {
                $systemPrompt = $msg['content'];
                continue;
            }

            $geminiContents[] = [
                'role'  => $msg['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $msg['content']]],
            ];
        }

        $model = $this->config('api_model');

        try {
            $response = Http::withHeaders([
                'x-goog-api-key' => $this->config('api_key'),
                'content-type'   => 'application/json',
            ])
                ->timeout(45)
                ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent", [
                    'contents'          => $geminiContents,
                    'systemInstruction' => [
                        'parts' => [['text' => $systemPrompt]],
                    ],
                    'generationConfig'  => [
                        'maxOutputTokens' => 1024,
                    ],
                ]);

            if (!$response->successful()) {
                Log::error('AISupport: Gemini API error', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return null;
            }

            return $response->json('candidates.0.content.parts.0.text');
        } catch (\Throwable $e) {
            Log::error('AISupport: Gemini request failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    // ── Discord notification ──────────────────────────────────────────────────

    private function sendDiscordNotification(\App\Models\Ticket $ticket, TicketMessage $message): void
    {
        $webhookUrl = $this->config('discord_webhook');
        if (empty($webhookUrl)) {
            return;
        }

        $preview = mb_substr($message->message, 0, 300);
        if (mb_strlen($message->message) > 300) {
            $preview .= '…';
        }

        try {
            Http::timeout(10)->post($webhookUrl, [
                'embeds' => [
                    [
                        'title'       => 'AI could not answer — manual follow-up needed',
                        'description' => "Ticket **#{$ticket->id}**: {$ticket->subject}",
                        'color'       => 0xF97316,
                        'fields'      => [
                            [
                                'name'   => 'Department',
                                'value'  => $ticket->department ?? '—',
                                'inline' => true,
                            ],
                            [
                                'name'   => 'Priority',
                                'value'  => ucfirst($ticket->priority ?? '—'),
                                'inline' => true,
                            ],
                            [
                                'name'   => 'Customer',
                                'value'  => $ticket->user->name ?? '—',
                                'inline' => true,
                            ],
                            [
                                'name'   => 'Latest message',
                                'value'  => $preview ?: '—',
                                'inline' => false,
                            ],
                        ],
                        'footer'    => ['text' => 'AI Support Extension · Paymenter'],
                        'timestamp' => now()->toIso8601String(),
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('AISupport: Discord webhook failed', ['error' => $e->getMessage()]);
        }
    }
}
