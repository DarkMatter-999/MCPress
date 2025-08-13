// eslint-disable-next-line camelcase
declare const mcpress_vars: {
	chat_url: string;
	chat_init_url: string;
	execute_tool_url: string;
	nonce: string;
};

// Array to store conversation messages
const messages: Array<{
	role: string;
	content?: string;
	tool_calls?: Array<{
		id: string;
		type: string;
		function: { name: string; arguments: string };
	}>;
	tool_call_id?: string;
}> = [];

const chatLog = document.getElementById('mcpress-chat-log') as HTMLElement;
const userInput = document.getElementById('mcpress-user-input') as HTMLTextAreaElement;
const sendButton = document.getElementById('mcpress-send-button') as HTMLButtonElement;
const spinner = document.querySelector('.spinner') as HTMLElement;

let currentToolCalls: Array<any> | null = null;
let currentMessagesHistory: Array<any> | null = null;

// Append messages to chat
function appendMessage(
	sender: string,
	message: string,
	type: 'user' | 'llm' | 'error' | 'tool-execution' | 'system'
): void {
	if (!message) {
		return;
	}
	let messageClass = 'user-message';
	if (type === 'llm') {
		messageClass = 'llm-message';
	} else if (type === 'error') {
		messageClass = 'error-message';
	} else if (type === 'tool-execution') {
		messageClass = 'tool-execution-message';
	}

	let formattedMessage = message; // Initialize with the original message

	// Markdown formatting only applies if the message is not a tool-execution
	if (type !== 'tool-execution') {
		formattedMessage = message
			.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>') // bold
			.replace(/`(.*?)`/g, '<code>$1</code>') // inline code
			.replace(/\n/g, '<br>'); // newlines
	}

	const messageDiv = document.createElement('div');
	messageDiv.className = `mcpress-chat-message ${messageClass}`;
	messageDiv.innerHTML = `<strong>${sender}:</strong> ${formattedMessage}`;
	chatLog.appendChild(messageDiv);

	// Scroll to bottom
	chatLog.scrollTop = chatLog.scrollHeight;
}

// Send button click handler
sendButton.addEventListener('click', () => sendMessage());

// Function to send message to the API
async function sendMessage(): Promise<void> {
	const messageText = userInput.value.trim();
	if (messageText === '' && messages.length > 0 && !currentToolCalls) {
		// Only proceed if there's a message from user or if it's a follow-up (tool execution)
		// but not if it's an empty message and no tool confirmation is pending.
		return;
	}

	if (messageText !== '') {
		appendMessage('You', messageText, 'user');
		messages.push({ role: 'user', content: messageText });
		userInput.value = '';
	}

	spinner.classList.add('is-active');
	sendButton.disabled = true;

	try {
		// eslint-disable-next-line camelcase
		const response = await fetch(mcpress_vars.chat_url, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				// eslint-disable-next-line camelcase
				'X-WP-Nonce': mcpress_vars.nonce
			},
			credentials: 'include',
			body: JSON.stringify({
				messages // Send the entire message history
			})
		});

		const data = await response.json();

		if (data.success) {
			if (data.requires_confirmation && data.tool_calls) {
				// Store tool calls and messages for later execution
				currentToolCalls = data.tool_calls;
				currentMessagesHistory = data.messages; // This should include the assistant's tool_calls message

				appendMessage('LLM', data.message, 'llm'); // "I am suggesting to use a tool..."
				messages.push({
					role: 'assistant',
					content: data.message
				});

				// Display Yes/No buttons
				const yesButton = document.createElement('button');
				yesButton.id = 'mcpress-tool-yes';
				yesButton.className = 'button button-primary';
				yesButton.textContent = 'Yes';
				yesButton.addEventListener('click', () => handleToolDecision('yes'));

				const noButton = document.createElement('button');
				noButton.id = 'mcpress-tool-no';
				noButton.className = 'button';
				noButton.textContent = 'No';
				noButton.addEventListener('click', () => handleToolDecision('no'));

				const buttonContainer = document.createElement('div');
				buttonContainer.className = 'mcpress-tool-decision';
				buttonContainer.appendChild(yesButton);
				buttonContainer.appendChild(noButton);
				chatLog.appendChild(buttonContainer);
				chatLog.scrollTop = chatLog.scrollHeight;
			} else {
				// Normal LLM response or final response after tool execution
				const llmResponseContent: string = data.message;
				if (llmResponseContent) {
					appendMessage('LLM', llmResponseContent, 'llm');
					if (!currentToolCalls) {
						messages.push({
							role: 'assistant',
							content: llmResponseContent
						});
					}
				} else {
					// Fallback for empty LLM response
					appendMessage('LLM', 'LLM did not provide a follow-up response.', 'llm');
					if (!currentToolCalls) {
						messages.push({
							role: 'assistant',
							content: 'LLM did not provide a follow-up response.'
						});
					}
				}
				// Reset tool confirmation state
				currentToolCalls = null;
				currentMessagesHistory = null;
			}
		} else {
			appendMessage('Error', data.message || 'An unknown error occurred.', 'error');
			// Reset tool confirmation state on error
			currentToolCalls = null;
			currentMessagesHistory = null;
		}
	} catch (error) {
		appendMessage('Error', `API request failed: ${error}`, 'error');
		// Reset tool confirmation state on error
		currentToolCalls = null;
		currentMessagesHistory = null;
	} finally {
		// Only remove spinner and re-enable button if no confirmation is pending
		if (!currentToolCalls) {
			spinner.classList.remove('is-active');
			sendButton.disabled = false;
		}
	}
}

// Function to handle tool decision (Yes/No buttons)
async function handleToolDecision(decision: 'yes' | 'no') {
	spinner.classList.add('is-active');
	sendButton.disabled = true;

	// Remove buttons to prevent multiple clicks
	document.getElementById('mcpress-tool-yes')?.remove();
	document.getElementById('mcpress-tool-no')?.remove();

	if (decision === 'yes' && currentToolCalls && currentMessagesHistory) {
		appendMessage('You', 'Yes, execute the tool.', 'user');
		// Push the user's "Yes" response into the message history
		messages.push({ role: 'user', content: 'Yes, execute the tool.' });

		try {
			// eslint-disable-next-line camelcase
			const response = await fetch(mcpress_vars.execute_tool_url, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					// eslint-disable-next-line camelcase
					'X-WP-Nonce': mcpress_vars.nonce
				},
				credentials: 'include',
				body: JSON.stringify({
					tool_calls: currentToolCalls,
					messages: currentMessagesHistory
				})
			}).then(res => res.json());

			if (response.success) {
				appendMessage('LLM', response.message, 'llm');
				// Push the final LLM response into the main messages array
				messages.push({
					role: 'assistant',
					content: response.message
				});
			} else {
				appendMessage('Error', response.message || 'An unknown error occurred during tool execution.', 'error');
				// Even on error, push an assistant message to keep history consistent
				messages.push({
					role: 'assistant',
					content: response.message || 'An unknown error occurred during tool execution.'
				});
			}
		} catch (error) {
			appendMessage('Error', `Tool execution API request failed: ${error}`, 'error');
			messages.push({
				role: 'assistant',
				content: `Tool execution API request failed: ${error}`
			});
		}
	} else if (decision === 'no') {
		appendMessage('You', 'No, do not execute the tool.', 'user');
		messages.push({
			role: 'user',
			content: 'No, do not execute the tool.'
		}); // Push user's "No"
		appendMessage('MCP Agent', 'Tool execution declined. How else can I assist?', 'llm');
		messages.push({
			role: 'assistant',
			content: 'Tool execution declined. How else can I assist?'
		}); // Push assistant's response
	}

	// Clear stored tool data
	currentToolCalls = null;
	currentMessagesHistory = null;

	spinner.classList.remove('is-active');
	sendButton.disabled = false;
}

// Handle Enter key for sending message
userInput.addEventListener('keypress', (e: KeyboardEvent) => {
	if (e.key === 'Enter' && !e.shiftKey) {
		e.preventDefault();
		sendMessage(); // Call sendMessage directly
	}
});

// Fetch initial chat context on page load
document.addEventListener('DOMContentLoaded', async () => {
	spinner.classList.add('is-active');
	sendButton.disabled = true;

	try {
		// eslint-disable-next-line camelcase
		const response = await fetch(mcpress_vars.chat_init_url, {
			method: 'GET',
			headers: {
				'Content-Type': 'application/json',
				// eslint-disable-next-line camelcase
				'X-WP-Nonce': mcpress_vars.nonce
			},
			credentials: 'include'
		});

		const data = await response.json();

		if (data.success && Array.isArray(data.messages)) {
			// Add system message to the history
			messages.push(...data.messages);

			// Display the welcome message as a system message
			if (data.display_initial_message) {
				appendMessage('System', data.display_initial_message, 'system');
			}
		} else {
			appendMessage('Error', data.message || 'Failed to load initial chat context.', 'error');
		}
	} catch (error) {
		appendMessage('Error', `Failed to fetch initial chat context: ${error}`, 'error');
	} finally {
		spinner.classList.remove('is-active');
		sendButton.disabled = false;
	}
});

// Handle Enter key for sending message
userInput.addEventListener('keypress', (e: KeyboardEvent) => {
	if (e.key === 'Enter' && !e.shiftKey) {
		e.preventDefault();
		sendButton.click();
	}
});
