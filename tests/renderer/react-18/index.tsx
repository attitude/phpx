import { Glob } from "bun"
import { renderToString } from "react-dom/server"

const files = new Glob("components/*.tsx")

for await (const file of files.scan(".")) {
	const { Component } = await import(`./${file}`)
	const data = await import(`./${file}.json`)
	const html = renderToString(<Component {...data} />, {
		identifierPrefix: "test:",
	})
	await Bun.write(`./${file}.html`, html)
}
