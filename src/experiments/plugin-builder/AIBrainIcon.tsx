export const AIBrainIcon: React.FC = () => (
	<svg className="apb-chat__ai-icon" width="512" height="512" viewBox="0 0 512 512" xmlns="http://www.w3.org/2000/svg">
		<defs>
			{/* Gradient for main elements */}
			<linearGradient id="mainGrad" x1="0" y1="0" x2="1" y2="1">
				<stop offset="0%" stopColor="#3858e9"/>
				<stop offset="100%" stopColor="#2c46c7"/>
			</linearGradient>

			{/* Glow filter */}
			<filter id="glow" x="-30%" y="-30%" width="160%" height="160%">
				<feGaussianBlur stdDeviation="3" result="blur"/>
				<feMerge>
					<feMergeNode in="blur"/>
					<feMergeNode in="SourceGraphic"/>
				</feMerge>
			</filter>

			{/* Light trail effect */}
			<linearGradient id="lightTrail" x1="0" y1="0" x2="1" y2="0">
				<stop offset="0%" stopColor="#3858e9" stopOpacity="0"/>
				<stop offset="50%" stopColor="#3858e9" stopOpacity="0.6"/>
				<stop offset="100%" stopColor="#3858e9" stopOpacity="0"/>
			</linearGradient>
		</defs>

		{/* Stars scattered around */}
		<g fill="#3858e9" opacity="0.4">
			<circle cx="80" cy="70" r="3"/>
			<circle cx="450" cy="90" r="2.5"/>
			<circle cx="470" cy="300" r="3"/>
			<circle cx="60" cy="420" r="2"/>
			<circle cx="420" cy="450" r="3"/>
			<circle cx="100" cy="250" r="2.5"/>
			<circle cx="430" cy="150" r="2"/>
		</g>

		{/* Light speed trails (motion lines) */}
		<g stroke="url(#lightTrail)" strokeWidth="4" strokeLinecap="round" fill="none" opacity="0.3">
			<path d="M 150 180 Q 200 200 256 200"/>
			<path d="M 160 240 Q 220 250 280 240"/>
			<path d="M 150 280 Q 210 290 270 280"/>
		</g>

		{/* Central AI node (main brain/core) */}
		<circle cx="256" cy="256" r="45" fill="url(#mainGrad)" filter="url(#glow)"/>

		{/* Neural connections - three outer nodes */}
		<g fill="none" stroke="#3858e9" strokeWidth="2.5" opacity="0.6">
			{/* Top node */}
			<line x1="256" y1="211" x2="256" y2="140"/>
			<circle cx="256" cy="130" r="18" fill="none" stroke="#3858e9" strokeWidth="2"/>
			
			{/* Left node */}
			<line x1="215" y1="278" x2="140" y2="310"/>
			<circle cx="130" cy="320" r="18" fill="none" stroke="#3858e9" strokeWidth="2"/>
			
			{/* Right node */}
			<line x1="297" y1="278" x2="370" y2="310"/>
			<circle cx="380" cy="320" r="18" fill="none" stroke="#3858e9" strokeWidth="2"/>
		</g>

		{/* Inner circles for neural nodes (filled) */}
		<g fill="#3858e9" opacity="0.7">
			<circle cx="256" cy="130" r="8"/>
			<circle cx="130" cy="320" r="8"/>
			<circle cx="380" cy="320" r="8"/>
		</g>

		{/* Pulsing energetic rings around center */}
		<g fill="none" stroke="#3858e9" opacity="0.3">
			<circle cx="256" cy="256" r="55" strokeWidth="1.5"/>
			<circle cx="256" cy="256" r="70" strokeWidth="1"/>
		</g>

		{/* Build concept - geometric blocks forming upward arrow */}
		<g fill="#3858e9" opacity="0.5">
			{/* Bottom left block */}
			<rect x="220" y="320" width="20" height="20" rx="2"/>
			{/* Bottom right block */}
			<rect x="272" y="320" width="20" height="20" rx="2"/>
			{/* Middle center block */}
			<rect x="246" y="290" width="20" height="20" rx="2"/>
			{/* Top point */}
			<circle cx="256" cy="260" r="5"/>
		</g>
	</svg>
);