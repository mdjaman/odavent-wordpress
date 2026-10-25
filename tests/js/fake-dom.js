'use strict';
/**
 * A very small stand-in for the DOM, just enough for assets/js/*.js: elements with a class list,
 * attributes, children, text and a click listener, plus a runner that evaluates a script in a sandbox
 * with a controllable clock. No dependencies; run the suites with bin/test-js.
 */
const fs = require( 'fs' );
const path = require( 'path' );
const vm = require( 'vm' );

function El( className, attrs, children ) {
	this.className = className;
	this.attrs = Object.assign( {}, attrs || {} );
	this.tagName = this.attrs._tag || 'DIV';
	delete this.attrs._tag;
	this.children = children || [];
	this.parentNode = null;
	this.textContent = '';
	this.listeners = {};
	this.children.forEach( ( c ) => {
		c.parentNode = this;
	} );
}
El.prototype.getAttribute = function ( k ) {
	return k in this.attrs ? this.attrs[ k ] : null;
};
El.prototype.setAttribute = function ( k, v ) {
	this.attrs[ k ] = String( v );
};
El.prototype.removeAttribute = function ( k ) {
	delete this.attrs[ k ];
};
El.prototype.hasAttribute = function ( k ) {
	return k in this.attrs;
};
El.prototype.all = function () {
	let out = [];
	this.children.forEach( ( c ) => {
		out.push( c );
		out = out.concat( c.all() );
	} );
	return out;
};
El.prototype.matches = function ( selector ) {
	if ( selector.charAt( 0 ) === '.' ) {
		return String( this.className ).split( ' ' ).indexOf( selector.slice( 1 ) ) !== -1;
	}
	const m = selector.match( /^\[(.+?)="(.+?)"\]$/ );
	return m ? this.attrs[ m[ 1 ] ] === m[ 2 ] : false;
};
El.prototype.querySelectorAll = function ( selector ) {
	return this.all().filter( ( e ) => e.matches( selector ) );
};
El.prototype.querySelector = function ( selector ) {
	return this.querySelectorAll( selector )[ 0 ] || null;
};
El.prototype.removeChild = function ( child ) {
	this.children = this.children.filter( ( c ) => c !== child );
	child.parentNode = null;
	return child;
};
El.prototype.replaceChild = function ( next, old ) {
	this.children = this.children.map( ( c ) => ( c === old ? next : c ) );
	next.parentNode = this;
	old.parentNode = null;
	return old;
};
El.prototype.addEventListener = function ( type, fn ) {
	this.listeners[ type ] = fn;
};

/**
 * Runs assets/js/{file} against a document that returns $roots for any selector.
 *
 * @param {string} file  Script file name.
 * @param {El[]}   roots Elements querySelectorAll() returns.
 * @param {{t:number}} clock Mutable clock (milliseconds) behind Date.now().
 * @return {{timers: Array, doc: object}} The setInterval callbacks (null once cleared) and the fake document.
 */
function run( file, roots, clock ) {
	const timers = [];
	const doc = {
		readyState: 'complete',
		querySelectorAll: () => roots,
		createElement: ( tag ) => new El( '', { _tag: String( tag ).toUpperCase() } ),
		// Last listener per type, with whether it was registered for the capture phase.
		listeners: {},
		addEventListener( type, fn, capture ) {
			doc.listeners[ type ] = { fn, capture: capture === true };
		},
	};
	const sandbox = {
		document: doc,
		Date: { parse: Date.parse, now: () => clock.t },
		setInterval: ( fn ) => {
			timers.push( fn );
			return timers.length;
		},
		clearInterval: ( id ) => {
			timers[ id - 1 ] = null;
		},
		isNaN,
		Math,
		String,
	};
	vm.runInNewContext( fs.readFileSync( path.join( __dirname, '..', '..', 'assets', 'js', file ), 'utf8' ), sandbox );
	return { timers, doc };
}

module.exports = { El, run };
