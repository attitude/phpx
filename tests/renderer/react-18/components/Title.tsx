import { useState } from "react"

export interface TitleProps {
	name: string
	children: string
}

export const Component = function Title(props: TitleProps) {
	const [count, setCount] = useState(0)

	return (
		<>
			<h1>
				{props.children} {props.name}
			</h1>
			<span>
				Count: {count}
				<button onClick={() => setCount((count) => count + 1)}>Increase</button>
			</span>
		</>
	)
}
