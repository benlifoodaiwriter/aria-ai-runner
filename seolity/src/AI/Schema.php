<?php
/**
 * JSON-Schema builder + validator for structured AI output.
 *
 * Schemas are built "strict": every object lists all properties as required and
 * forbids additional properties, which is what OpenAI strict mode and Anthropic
 * structured outputs expect. Optional values are expressed as nullable types.
 *
 * @package SEOlity
 */

namespace SEOlity\AI;

defined( 'ABSPATH' ) || exit;

final class Schema {

	public static function str( $description = '' ) {
		return self::d( array( 'type' => 'string' ), $description );
	}

	public static function int( $description = '' ) {
		return self::d( array( 'type' => 'integer' ), $description );
	}

	public static function num( $description = '' ) {
		return self::d( array( 'type' => 'number' ), $description );
	}

	public static function bool( $description = '' ) {
		return self::d( array( 'type' => 'boolean' ), $description );
	}

	public static function enum( array $values, $description = '' ) {
		return self::d( array( 'type' => 'string', 'enum' => array_values( $values ) ), $description );
	}

	public static function arr( array $items, $description = '' ) {
		return self::d( array( 'type' => 'array', 'items' => $items ), $description );
	}

	public static function strings( $description = '' ) {
		return self::arr( self::str(), $description );
	}

	public static function nullable( array $schema ) {
		$schema['type'] = array( $schema['type'], 'null' );
		if ( isset( $schema['enum'] ) ) {
			$schema['enum'][] = null;
		}
		return $schema;
	}

	public static function obj( array $properties, $description = '' ) {
		return self::d(
			array(
				'type'                 => 'object',
				'properties'           => $properties,
				'required'             => array_keys( $properties ),
				'additionalProperties' => false,
			),
			$description
		);
	}

	private static function d( array $schema, $description ) {
		if ( '' !== $description ) {
			$schema['description'] = $description;
		}
		return $schema;
	}

	/**
	 * Validate data against a (subset of) JSON Schema.
	 *
	 * @return string[] Errors (empty = valid).
	 */
	public static function validate( $data, array $schema, $path = '$' ) {
		$errors = array();
		$types  = (array) ( isset( $schema['type'] ) ? $schema['type'] : array() );
		if ( in_array( 'null', $types, true ) && null === $data ) {
			return $errors;
		}
		$type = $types ? $types[0] : null;
		switch ( $type ) {
			case 'object':
				if ( ! is_array( $data ) || ( $data && array_keys( $data ) === range( 0, count( $data ) - 1 ) ) ) {
					return array( "$path must be an object" );
				}
				foreach ( (array) ( isset( $schema['required'] ) ? $schema['required'] : array() ) as $req ) {
					if ( ! array_key_exists( $req, $data ) ) {
						$errors[] = "$path.$req is required";
					}
				}
				foreach ( (array) ( isset( $schema['properties'] ) ? $schema['properties'] : array() ) as $key => $sub ) {
					if ( array_key_exists( $key, $data ) ) {
						$errors = array_merge( $errors, self::validate( $data[ $key ], $sub, "$path.$key" ) );
					}
				}
				break;
			case 'array':
				if ( ! is_array( $data ) || ( $data && array_keys( $data ) !== range( 0, count( $data ) - 1 ) ) ) {
					return array( "$path must be an array" );
				}
				if ( isset( $schema['items'] ) ) {
					foreach ( $data as $i => $item ) {
						$errors = array_merge( $errors, self::validate( $item, $schema['items'], "{$path}[$i]" ) );
						if ( count( $errors ) > 20 ) {
							break;
						}
					}
				}
				break;
			case 'string':
				if ( ! is_string( $data ) ) {
					$errors[] = "$path must be a string";
				} elseif ( isset( $schema['enum'] ) && ! in_array( $data, $schema['enum'], true ) ) {
					$errors[] = "$path must be one of " . implode( ', ', array_filter( $schema['enum'], 'is_string' ) );
				}
				break;
			case 'integer':
				if ( ! is_int( $data ) && ! ( is_float( $data ) && floor( $data ) === $data ) ) {
					$errors[] = "$path must be an integer";
				}
				break;
			case 'number':
				if ( ! is_int( $data ) && ! is_float( $data ) ) {
					$errors[] = "$path must be a number";
				}
				break;
			case 'boolean':
				if ( ! is_bool( $data ) ) {
					$errors[] = "$path must be a boolean";
				}
				break;
		}
		return $errors;
	}

	/** Extract the first JSON object from model text (tolerates fences/preamble). */
	public static function parse_json( $text ) {
		$text = trim( (string) $text );
		$data = json_decode( $text, true );
		if ( JSON_ERROR_NONE === json_last_error() && is_array( $data ) ) {
			return $data;
		}
		if ( preg_match( '/```(?:json)?\s*(.+?)```/s', $text, $m ) ) {
			$data = json_decode( trim( $m[1] ), true );
			if ( is_array( $data ) ) {
				return $data;
			}
		}
		$start = strpos( $text, '{' );
		$end   = strrpos( $text, '}' );
		if ( false !== $start && false !== $end && $end > $start ) {
			$data = json_decode( substr( $text, $start, $end - $start + 1 ), true );
			if ( is_array( $data ) ) {
				return $data;
			}
		}
		return null;
	}
}
