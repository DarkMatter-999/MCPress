// eslint-disable-next-line camelcase
declare const mcpress_vars: {
	chat_url: string;
	nonce: string;
};

const chatLog = document.getElementById( 'mcpress-chat-log' ) as HTMLElement;
const userInput = document.getElementById(
	'mcpress-user-input'
) as HTMLTextAreaElement;
const sendButton = document.getElementById(
	'mcpress-send-button'
) as HTMLButtonElement;
const spinner = document.querySelector( '.spinner' ) as HTMLElement;

// Append messages to chat
function appendMessage(
	sender: string,
	message: string,
	type: 'user' | 'llm' | 'error' | 'tool-execution'
): void {
	if ( ! message ) {
		return;
	}
	let messageClass = 'user-message';
	if ( type === 'llm' ) {
		messageClass = 'llm-message';
	} else if ( type === 'error' ) {
		messageClass = 'error-message';
	} else if ( type === 'tool-execution' ) {
		messageClass = 'tool-execution-message';
	}

	let formattedMessage = message; // Initialize with the original message

	// Markdown formatting only applies if the message is not a tool-execution
	if ( type !== 'tool-execution' ) {
		formattedMessage = message
			.replace( /\*\*(.*?)\*\*/g, '<strong>$1</strong>' ) // bold
			.replace( /`(.*?)`/g, '<code>$1</code>' ) // inline code
			.replace( /\n/g, '<br>' ); // newlines
	}

	const messageDiv = document.createElement( 'div' );
	messageDiv.className = `mcpress-chat-message ${ messageClass }`;
	messageDiv.innerHTML = `<strong>${ sender }:</strong> ${ formattedMessage }`;
	chatLog.appendChild( messageDiv );

	// Scroll to bottom
	chatLog.scrollTop = chatLog.scrollHeight;
}

// Send button click handler
sendButton.addEventListener( 'click', () => {
	const message = userInput.value.trim();
	if ( message === '' ) {
		return;
	}

	appendMessage( 'You', message, 'user' );
	userInput.value = '';
	spinner.classList.add( 'is-active' );
	sendButton.disabled = true;

	// Send CHAT API request
	// eslint-disable-next-line camelcase
	fetch( mcpress_vars.chat_url, {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json',
			// eslint-disable-next-line camelcase
			'X-WP-Nonce': mcpress_vars.nonce,
		},
		credentials: 'include',
		body: JSON.stringify( {
			message,
		} ),
	} )
		.then( ( response ) => response.json() )
		.then( ( response ) => {
			if ( response.success ) {
				const toolExecutionMarker = '**Executed Tool:**';
				const responseText: string = response.message;

				if ( responseText.includes( toolExecutionMarker ) ) {
					const parts = responseText.split( toolExecutionMarker );
					if ( parts.length > 1 ) {
						const toolOutput = parts[ 1 ].split( ' (Note:' )[ 0 ];
						appendMessage(
							'MCP Agent',
							toolExecutionMarker + toolOutput,
							'tool-execution'
						);

						const remaining = parts[ 1 ].includes( '(Note:' )
							? parts[ 1 ].split( '(Note:' )[ 0 ]
							: parts[ 1 ];

						appendMessage(
							'LLM',
							remaining.replace( toolExecutionMarker, '' ).trim(),
							'llm'
						);
					} else {
						appendMessage( 'LLM', responseText, 'llm' );
					}
				} else {
					appendMessage( 'LLM', responseText, 'llm' );
				}
			} else {
				appendMessage(
					'Error',
					response.message || 'An unknown error occurred.',
					'error'
				);
			}
		} )
		.catch( ( error ) => {
			appendMessage( 'Error', `API request failed: ${ error }`, 'error' );
		} )
		.finally( () => {
			spinner.classList.remove( 'is-active' );
			sendButton.disabled = false;
		} );
} );

// Handle Enter key for sending message
userInput.addEventListener( 'keypress', ( e: KeyboardEvent ) => {
	if ( e.key === 'Enter' && ! e.shiftKey ) {
		e.preventDefault();
		sendButton.click();
	}
} );
